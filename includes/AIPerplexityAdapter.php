<?php

/**
 * @file
 * Perplexity AI adapter for AI core.
 */

class AIPerplexityAdapter extends AIAdapterBase {

  use AICompatibleTrait;

  /** @var string */
  protected $baseUrl = 'https://api.perplexity.ai';

  /** @var array|null */
  protected $models = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct($api_key, ?AIApi $api = NULL) {
    parent::__construct($api_key, $api);
    $config_base = config_get('ai_provider_perplexity.settings', 'base_url');
    if (!empty($config_base)) {
      $this->baseUrl = rtrim($config_base, '/');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultHeaders(): array {
    return [
      'Authorization' => 'Bearer ' . $this->apiKey,
      'Content-Type' => 'application/json',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    if ($this->models !== NULL) {
      return $this->models;
    }

    $models = [
      'sonar' => 'Perplexity Sonar (Online Search)',
      'sonar-pro' => 'Perplexity Sonar Pro (Advanced Search)',
      'sonar-reasoning' => 'Perplexity Sonar Reasoning (Search & CoT)',
      'sonar-reasoning-pro' => 'Perplexity Sonar Reasoning Pro',
    ];

    asort($models);
    return $this->models = $models;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelsByCapability($capability): array {
    $models = $this->getModels();
    $capability = ai_normalize_capability_name($capability);
    $filtered = [];

    foreach ($models as $id => $label) {
      $ok = FALSE;
      switch ($capability) {
        case 'text':
        case 'chat':
          $ok = TRUE;
          break;

        case 'thinking':
          $ok = (bool) preg_match('/reasoning/i', $id);
          break;

        case 'tool_calling':
        case 'vision':
        case 'embeddings':
        case 'embedding':
        case 'image':
        case 'moderation':
        case 'stt':
          $ok = FALSE;
          break;
      }

      if ($ok) {
        $filtered[$id] = $label;
      }
    }

    backdrop_alter('ai_model_capabilities', $filtered, $capability, $this);
    return $filtered;
  }

  /**
   * {@inheritdoc}
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    $messages = [
      ['role' => 'user', 'content' => $prompt],
    ];
    return $this->chat($model, $messages, $temperature, $max_tokens, $stream_response);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE, array $context_extra = []) {
    $url = $this->baseUrl . '/chat/completions';

    $payload = [
      'model' => $model,
      'messages' => $messages,
      'temperature' => (float) $temperature,
      'return_citations' => TRUE,
    ];

    if ((int) $max_tokens > 0) {
      $payload['max_tokens'] = (int) $max_tokens;
    }

    if (!empty($context_extra['search_domain_filter'])) {
      $payload['search_domain_filter'] = (array) $context_extra['search_domain_filter'];
    }

    if (!empty($context_extra['response_format'])) {
      $payload['response_format'] = $context_extra['response_format'];
    }
    elseif (!empty($context_extra['json_schema'])) {
      $payload['response_format'] = [
        'type' => 'json_schema',
        'json_schema' => [
          'name' => $context_extra['json_schema_name'] ?? 'response',
          'strict' => TRUE,
          'schema' => $context_extra['json_schema'],
        ],
      ];
    }
    elseif (!empty($context_extra['json_mode'])) {
      $payload['response_format'] = ['type' => 'json_object'];
    }

    try {
      if ($stream_response) {
        $payload['stream'] = TRUE;
        return $this->buildStreamingResponse($url, [
          'method' => 'POST',
          'headers' => array_merge(['Accept' => 'text/event-stream'], $this->getDefaultHeaders()),
          'data' => json_encode($payload),
          'timeout' => 300,
        ], function ($data) {
          return $data['choices'][0]['delta']['content'] ?? '';
        });
      }

      $result = $this->makeRequest($url, $payload, [], 'POST', 300);
      $content = trim($result['choices'][0]['message']['content'] ?? '');

      // Append citations if available and requested in context.
      if (!empty($context_extra['append_citations']) && !empty($result['citations'])) {
        $citations_text = "\n\n### Sources:\n";
        foreach ($result['citations'] as $idx => $citation_url) {
          $citations_text .= "[" . ($idx + 1) . "] " . $citation_url . "\n";
        }
        $content .= $citations_text;
      }

      return $content;
    }
    catch (\Exception $e) {
      watchdog('ai_provider_perplexity', 'Perplexity chat error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chatWithTools(string $model, array $messages, array $tools, $temperature, $max_tokens = 1024, string $tool_choice = 'auto', array $context_extra = []): array {
    // Perplexity does not support custom function calling. Emulate via standard chat.
    $prompt = "You are an assistant that must call a function. Available tools:\n" . json_encode($tools) . "\n\nPlease respond with a JSON object format: {\"name\": \"function_name\", \"arguments\": {...}}";
    $augmented_messages = array_merge([['role' => 'system', 'content' => $prompt]], $messages);
    $response = $this->chat($model, $augmented_messages, $temperature, $max_tokens, FALSE, $context_extra);

    // Models often wrap JSON in a markdown fence; strip it before decoding.
    $json = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', $response);
    $parsed = json_decode($json, TRUE);
    if (is_array($parsed) && !empty($parsed['name'])) {
      $args = $parsed['arguments'] ?? [];
      if (is_string($args)) {
        $args = json_decode($args, TRUE) ?? [];
      }
      // Same shape as AICompatibleTrait::normalizeToolResponse().
      return [
        'finish_reason' => 'tool_calls',
        'content' => '',
        'tool_calls' => [
          [
            'id' => 'call_' . uniqid(),
            'name' => $parsed['name'],
            'arguments' => is_array($args) ? $args : [],
          ],
        ],
        'raw' => $response,
      ];
    }

    return [
      'finish_reason' => 'stop',
      'content' => $response,
      'tool_calls' => [],
      'raw' => $response,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array {
    watchdog('ai_provider_perplexity', 'Embeddings are not supported by Perplexity.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Embeddings are not supported by Perplexity.');
  }

  /**
   * {@inheritdoc}
   */
  public function images(string $model, string $prompt, string $size, string $response_format, string $quality = 'standard', string $style = 'natural', ?string $output_format = NULL) {
    watchdog('ai_provider_perplexity', 'Image generation is not supported by Perplexity.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Image generation is not supported by Perplexity.');
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    watchdog('ai_provider_perplexity', 'Text-to-speech is not supported by Perplexity.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Text-to-speech is not supported by Perplexity.');
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    watchdog('ai_provider_perplexity', 'Speech-to-text is not supported by Perplexity.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Speech-to-text is not supported by Perplexity.');
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string $input, string $model = 'omni-moderation-latest'): array {
    watchdog('ai_provider_perplexity', 'Moderation is not supported by Perplexity.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Moderation is not supported by Perplexity.');
  }

}
