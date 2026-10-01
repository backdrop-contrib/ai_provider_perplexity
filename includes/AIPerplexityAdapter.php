<?php

/**
 * @file
 * Perplexity adapter for AI core, built on the Agent API.
 *
 * Sonar Chat Completions support ended on 2026-09-27. The Agent API
 * (POST /v1/agent) takes an `input` array of typed items and returns an
 * `output` array; it serves Perplexity's own and third-party models, with web
 * search as an optional built-in tool and real function calling. Embeddings
 * (/v1/embeddings) and decisions (/v1/decisions) are separate endpoints.
 */

class AIPerplexityAdapter extends AIAdapterBase {

  /** @var string */
  protected $baseUrl = 'https://api.perplexity.ai';

  /** @var array|null */
  protected $models = NULL;

  /**
   * Model IDs from /v1/models (Agent API models).
   *
   * @var array
   */
  protected $agentModels = [];

  /** @var array */
  protected $embeddingModels = [];

  /** @var array */
  protected $decisionModels = [];

  /** @var bool */
  protected $webSearch = TRUE;

  /** @var int */
  protected $maxSteps = 3;

  /**
   * {@inheritdoc}
   */
  public function __construct($api_key, ?AIApi $api = NULL) {
    parent::__construct($api_key, $api);
    $config = config('ai_provider_perplexity.settings');
    $base = trim((string) $config->get('base_url'));
    if ($base !== '') {
      $this->baseUrl = rtrim($base, '/');
    }
    $this->webSearch = (bool) ($config->get('web_search') ?? TRUE);
    $this->maxSteps = max(1, min(100, (int) ($config->get('max_steps') ?: 3)));
    // Neither endpoint has a model listing, so these models come from
    // settings.
    $this->embeddingModels = $this->parseModelList($config->get('embedding_models'));
    $this->decisionModels = $this->parseModelList($config->get('decision_models'));
  }

  /**
   * Parse a one-model-per-line settings value.
   */
  protected function parseModelList($value): array {
    $models = [];
    foreach (preg_split('/[\r\n,]+/', (string) $value) as $line) {
      $line = trim($line);
      if ($line !== '' && $line[0] !== '#') {
        $models[$line] = $line;
      }
    }
    return $models;
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

    $this->agentModels = [];
    try {
      $result = $this->makeRequest($this->baseUrl . '/v1/models', [], [], 'GET', 10);
      foreach ($result['data'] ?? [] as $model) {
        $id = $model['id'] ?? NULL;
        if (!empty($id)) {
          $this->agentModels[$id] = $id;
        }
      }
    }
    catch (\Exception $e) {
      watchdog('ai_provider_perplexity', 'Failed to fetch Perplexity models: @message', ['@message' => $e->getMessage()], WATCHDOG_WARNING);
    }

    $models = $this->agentModels + $this->embeddingModels + $this->decisionModels;
    asort($models);
    return $this->models = $models;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelsByCapability($capability): array {
    $this->getModels();
    $capability = ai_normalize_capability_name($capability);

    switch ($capability) {
      // /v1/models has no capability metadata; every Agent API model takes
      // text and custom functions. Vision and thinking are assigned on the
      // Model capabilities page.
      case 'text':
      case 'tool_calling':
        $filtered = $this->agentModels;
        break;

      case 'embeddings':
        $filtered = $this->embeddingModels;
        break;

      case 'decision':
        $filtered = $this->decisionModels;
        break;

      default:
        $filtered = [];
        break;
    }

    backdrop_alter('ai_model_capabilities', $filtered, $capability, $this);
    return $filtered;
  }

  /**
   * Convert chat-completions messages into Agent API input items.
   */
  protected function buildInput(array $messages): array {
    $input = [];
    foreach ($messages as $message) {
      $role = $message['role'] ?? 'user';

      if ($role === 'tool') {
        $content = $message['content'] ?? '';
        $input[] = [
          'type' => 'function_call_output',
          'call_id' => (string) ($message['tool_call_id'] ?? ''),
          'output' => is_string($content) ? $content : json_encode($content),
        ];
        continue;
      }

      $content = $this->convertContent($message['content'] ?? '', $role);
      if ($content !== '' && $content !== []) {
        $input[] = [
          'type' => 'message',
          'role' => in_array($role, ['user', 'assistant', 'system', 'developer'], TRUE) ? $role : 'user',
          'content' => $content,
        ];
      }

      if ($role === 'assistant') {
        foreach ($message['tool_calls'] ?? [] as $call) {
          $arguments = $call['function']['arguments'] ?? ($call['arguments'] ?? '{}');
          $item = [
            'type' => 'function_call',
            'call_id' => (string) ($call['id'] ?? ''),
            'name' => (string) ($call['function']['name'] ?? ($call['name'] ?? '')),
            'arguments' => is_string($arguments) ? $arguments : json_encode($arguments),
          ];
          // Thinking models reject a replayed call without its signature.
          if (!empty($call['thought_signature'])) {
            $item['thought_signature'] = $call['thought_signature'];
          }
          $input[] = $item;
        }
      }
    }
    return $input;
  }

  /**
   * Convert chat-completions message content into Agent API content.
   *
   * @return string|array
   *   A string, or input_text/input_image parts for multimodal user content.
   */
  protected function convertContent($content, string $role) {
    if (!is_array($content)) {
      return (string) $content;
    }

    $parts = [];
    $text = '';
    foreach ($content as $part) {
      $type = $part['type'] ?? '';
      if ($type === 'text' || $type === 'input_text') {
        $parts[] = ['type' => 'input_text', 'text' => (string) ($part['text'] ?? '')];
        $text .= ($text === '' ? '' : "\n") . ($part['text'] ?? '');
      }
      elseif ($type === 'image_url' || $type === 'input_image') {
        $url = $part['image_url']['url'] ?? ($part['image_url'] ?? '');
        if (is_string($url) && $url !== '') {
          $parts[] = ['type' => 'input_image', 'image_url' => $url];
        }
      }
    }
    // Only user messages take image parts; flatten the rest to text.
    return $role === 'user' ? $parts : $text;
  }

  /**
   * Build the shared Agent API payload.
   */
  protected function buildPayload(string $model, array $messages, $temperature, $max_tokens, array $context_extra, array $function_tools = []): array {
    $payload = [
      'model' => $model,
      'input' => $this->buildInput($messages),
      // Required by anthropic/* models; the API returns 400 without it.
      'max_output_tokens' => (int) $max_tokens > 0 ? (int) $max_tokens : 4096,
      'temperature' => (float) $temperature,
    ];

    $tools = $function_tools;
    $web_search = $context_extra['web_search'] ?? $this->webSearch;
    if ($web_search) {
      $search = ['type' => 'web_search'];
      if (!empty($context_extra['search_domain_filter'])) {
        $search['filters']['search_domain_filter'] = array_values((array) $context_extra['search_domain_filter']);
      }
      $tools[] = $search;
    }
    if ($tools) {
      $payload['tools'] = $tools;
    }

    // Without a preset max_steps defaults to 1, which leaves no step to answer
    // after a search.
    if ($web_search) {
      $payload['max_steps'] = max(2, $this->maxSteps);
    }

    if (!empty($context_extra['response_format']['type']) && $context_extra['response_format']['type'] === 'json_schema') {
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
    elseif (!empty($context_extra['json_mode']) || (($context_extra['response_format']['type'] ?? '') === 'json_object')) {
      // The Agent API has only json_schema; an open object schema is the
      // equivalent of JSON mode.
      $payload['response_format'] = [
        'type' => 'json_schema',
        'json_schema' => ['name' => 'response', 'schema' => ['type' => 'object']],
      ];
    }

    return $payload;
  }

  /**
   * POST to /v1/agent and check the run status.
   *
   * Some models reject a non-default temperature; that 400 is retried once
   * without it rather than guessing which models those are.
   */
  protected function runAgent(array $payload): array {
    $url = $this->baseUrl . '/v1/agent';
    try {
      $result = $this->makeRequest($url, $payload, [], 'POST', 300);
    }
    catch (\Exception $e) {
      if (strpos($e->getMessage(), 'API error (400)') !== 0 || strpos($e->getMessage(), 'temperature') === FALSE) {
        throw $e;
      }
      unset($payload['temperature']);
      $result = $this->makeRequest($url, $payload, [], 'POST', 300);
    }

    $status = $result['status'] ?? 'completed';
    if ($status === 'failed' || $status === 'cancelled') {
      throw new \RuntimeException('Perplexity run ' . $status . ': ' . ($result['error']['message'] ?? 'no error message'));
    }
    return $result;
  }

  /**
   * Collect the answer text, citations and function calls from a run.
   */
  protected function parseOutput(array $result): array {
    $text = '';
    $citations = [];
    $tool_calls = [];

    foreach ($result['output'] ?? [] as $item) {
      $type = $item['type'] ?? '';
      if ($type === 'message') {
        foreach ($item['content'] ?? [] as $part) {
          if (($part['type'] ?? '') === 'output_text') {
            $text .= $part['text'] ?? '';
            foreach ($part['annotations'] ?? [] as $annotation) {
              if (!empty($annotation['url'])) {
                $citations[$annotation['url']] = $annotation['title'] ?? $annotation['url'];
              }
            }
          }
        }
      }
      elseif ($type === 'function_call') {
        $arguments = json_decode((string) ($item['arguments'] ?? ''), TRUE);
        $call = [
          'id' => (string) ($item['call_id'] ?? ''),
          'name' => (string) ($item['name'] ?? ''),
          'arguments' => is_array($arguments) ? $arguments : [],
        ];
        if (!empty($item['thought_signature'])) {
          $call['thought_signature'] = $item['thought_signature'];
        }
        $tool_calls[] = $call;
      }
    }

    if ($tool_calls) {
      $finish_reason = 'tool_calls';
    }
    else {
      $finish_reason = ($result['status'] ?? '') === 'incomplete' ? 'length' : 'stop';
    }

    return [
      'content' => trim($text),
      'citations' => $citations,
      'tool_calls' => $tool_calls,
      'finish_reason' => $finish_reason,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    return $this->chat($model, [['role' => 'user', 'content' => $prompt]], $temperature, $max_tokens, $stream_response);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE, array $context_extra = []) {
    $payload = $this->buildPayload($model, $messages, $temperature, $max_tokens, $context_extra);

    try {
      if ($stream_response) {
        $payload['stream'] = TRUE;
        return $this->buildStreamingResponse($this->baseUrl . '/v1/agent', [
          'method' => 'POST',
          'headers' => array_merge(['Accept' => 'text/event-stream'], $this->getDefaultHeaders()),
          'data' => json_encode($payload),
          'timeout' => 300,
        ], function ($data) {
          // Only answer text; search and reasoning events carry no text.
          return ($data['type'] ?? '') === 'response.output_text.delta' ? (string) ($data['delta'] ?? '') : '';
        });
      }

      $parsed = $this->parseOutput($this->runAgent($payload));
      $content = $parsed['content'];
      if (!empty($context_extra['append_citations']) && $parsed['citations']) {
        $content .= "\n\n### Sources:\n";
        $i = 1;
        foreach (array_keys($parsed['citations']) as $url) {
          $content .= '[' . $i++ . '] ' . $url . "\n";
        }
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
    // Agent API function tools are flat: {type, name, description,
    // parameters}. It has no tool_choice parameter, so 'required' and named
    // choices can't be forced.
    $function_tools = [];
    foreach ($tools as $tool) {
      $function = $tool['function'] ?? $tool;
      if (empty($function['name'])) {
        continue;
      }
      $function_tools[] = array_filter([
        'type' => 'function',
        'name' => $function['name'],
        'description' => $function['description'] ?? NULL,
        'parameters' => $function['parameters'] ?? NULL,
      ], function ($value) {
        return $value !== NULL;
      });
    }
    if ($tool_choice === 'none') {
      $function_tools = [];
    }

    $payload = $this->buildPayload($model, $messages, $temperature, $max_tokens, $context_extra, $function_tools);

    try {
      $result = $this->runAgent($payload);
      $parsed = $this->parseOutput($result);
      return [
        'finish_reason' => $parsed['finish_reason'],
        'content' => $parsed['content'],
        'tool_calls' => $parsed['tool_calls'],
        'raw' => $result,
      ];
    }
    catch (\Exception $e) {
      watchdog('ai_provider_perplexity', 'Perplexity chatWithTools error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array {
    try {
      $result = $this->makeRequest($this->baseUrl . '/v1/embeddings', [
        'model' => $model,
        'input' => $input,
      ], [], 'POST', 60);
    }
    catch (\Exception $e) {
      watchdog('ai_provider_perplexity', 'Perplexity embedding error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }

    // Vectors arrive base64-encoded as signed int8 (the default encoding).
    $encoded = $result['data'][0]['embedding'] ?? NULL;
    if (is_array($encoded)) {
      return array_map('floatval', $encoded);
    }
    $bytes = is_string($encoded) ? base64_decode($encoded, TRUE) : FALSE;
    if ($bytes === FALSE || $bytes === '') {
      throw new \RuntimeException('Perplexity returned no embedding vector for ' . $model . '.');
    }
    return array_map('floatval', array_values(unpack('c*', $bytes)));
  }

  /**
   * {@inheritdoc}
   */
  public function decide(string $input, array $questions, string $model = '', array $context_extra = []): array {
    if (empty($questions)) {
      return [];
    }
    if ($model === '') {
      $model = (string) array_key_first($this->decisionModels);
    }
    // A chat model (or no decision model configured) uses the emulation.
    if ($model === '' || !isset($this->decisionModels[$model])) {
      return parent::decide($input, $questions, $model, $context_extra);
    }

    [$question_map, $meta] = AIDecisionHelper::buildQuestions($questions);
    try {
      $response = $this->makeRequest($this->baseUrl . '/v1/decisions', [
        'model' => $model,
        'state' => $input,
        'questions' => $question_map,
      ], [], 'POST', 60);
      return AIDecisionHelper::parseAnswers($response, $meta);
    }
    catch (\Exception $e) {
      watchdog('ai_provider_perplexity', 'Perplexity decision request failed for model @model: @message', [
        '@model' => $model,
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      throw $e;
    }
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
