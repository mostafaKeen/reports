<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class GeminiService
{
    private $apiKey;
    private ?BitrixClient $bitrixClient = null;
    private const API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';
    private const MODEL = 'gemini-flash-latest';
    private const MAX_TOOL_ROUNDS = 5; // Max back-and-forth tool call rounds

    public function __construct(string $apiKey)
    {
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('Gemini API key is required');
        }
        $this->apiKey = $apiKey;
    }

    /**
     * Attach a BitrixClient so the model can make live CRM API calls.
     */
    public function setBitrixClient(BitrixClient $client): self
    {
        $this->bitrixClient = $client;
        return $this;
    }

    /**
     * Generate a response from Gemini based on user question.
     * If a BitrixClient is attached, function calling is enabled so the model
     * can autonomously query Bitrix24 REST API methods.
     * 
     * @param string $question User's question
     * @param array $context Additional context (company info, CRM data, etc.)
     * @return string AI-generated response
     * @throws \Exception
     */
    public function generateResponse(string $question, array $context = []): string
    {
        try {
            // Build system context for better CRM-specific responses
            $systemContext = $this->buildSystemContext($context);
            
            $prompt = "{$systemContext}\n\nUser Question: {$question}";

            // Start the conversation with the user's message
            $contents = [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ];

            $payload = $this->buildPayload($contents);

            // Add function calling tools if BitrixClient is available
            if ($this->bitrixClient) {
                $payload['tools'] = [$this->getToolDeclarations()];
            }

            Log::info('Gemini API Request', [
                'endpoint' => self::API_ENDPOINT,
                'has_tools' => $this->bitrixClient !== null,
                'question' => substr($question, 0, 100),
            ]);

            // --- Function-call resolution loop ---
            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
                $response = Http::timeout(60)
                    ->withHeaders([
                        'X-goog-api-key' => $this->apiKey,
                    ])
                    ->post(self::API_ENDPOINT, $payload);

                Log::info('Gemini API Response', [
                    'status' => $response->status(),
                    'round' => $round,
                    'body_length' => strlen($response->body()),
                ]);

                if (!$response->successful()) {
                    throw new \Exception('Gemini API request failed: ' . $response->status() . ' - ' . $response->body());
                }

                $result = $response->json();

                if ($this->hasErrors($result)) {
                    throw new \Exception($this->extractErrorMessage($result));
                }

                $candidate = $result['candidates'][0] ?? null;
                if (!$candidate) {
                    throw new \Exception('No candidates in Gemini response');
                }

                $parts = $candidate['content']['parts'] ?? [];

                // Check if the model wants to call a function
                $functionCalls = $this->extractFunctionCalls($parts);

                if (!empty($functionCalls) && $this->bitrixClient) {
                    Log::info('Gemini requested function calls', [
                        'round' => $round,
                        'calls' => array_map(fn($fc) => $fc['name'], $functionCalls),
                    ]);

                    // Add model's response (with functionCall) to conversation
                    $contents[] = $candidate['content'];

                    // Execute each function call and collect results
                    $functionResponseParts = [];
                    foreach ($functionCalls as $fc) {
                        $toolResult = $this->executeBitrixCall($fc['name'], $fc['args'] ?? []);
                        $functionResponseParts[] = [
                            'functionResponse' => [
                                'name' => $fc['name'],
                                'response' => $toolResult,
                            ]
                        ];
                    }

                    // Add function results to conversation
                    $contents[] = [
                        'role' => 'function',
                        'parts' => $functionResponseParts,
                    ];

                    // Rebuild payload with updated conversation
                    $payload = $this->buildPayload($contents);
                    if ($this->bitrixClient) {
                        $payload['tools'] = [$this->getToolDeclarations()];
                    }

                    // Continue loop — Gemini will process the tool results
                    continue;
                }

                // No function call — we have a final text response
                return $this->extractTextFromResponse($result);
            }

            // If we exhausted all rounds, return whatever text we have
            return $this->extractTextFromResponse($result ?? []);

        } catch (\Exception $e) {
            Log::error('Gemini Service error', [
                'error' => $e->getMessage(),
                'question' => substr($question, 0, 100),
            ]);
            throw $e;
        }
    }

    /**
     * Generate a brief insight about CRM data
     * 
     * @param string $dataType Type of data (leads, activities, etc.)
     * @param array $data The data to analyze
     * @return string AI-generated insight
     * @throws \Exception
     */
    public function analyzeData(string $dataType, array $data): string
    {
        try {
            $dataJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            
            $prompt = <<<EOT
You are a CRM analytics expert. Analyze the following $dataType data and provide actionable insights in 2-3 bullet points.

Data:
$dataJson

Provide concise, actionable insights.
EOT;

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.5,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 512,
                ]
            ];

            Log::info('Gemini API Data Analysis Request', [
                'endpoint' => self::API_ENDPOINT,
                'payload' => $payload,
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'X-goog-api-key' => $this->apiKey,
                ])
                ->post(self::API_ENDPOINT, $payload);

            Log::info('Gemini API Data Analysis Response', [
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                throw new \Exception('Gemini API request failed: ' . $response->status() . ' - ' . $response->body());
            }

            $result = $response->json();

            if ($this->hasErrors($result)) {
                throw new \Exception($this->extractErrorMessage($result));
            }

            return $this->extractTextFromResponse($result);
        } catch (\Exception $e) {
            Log::error('Gemini data analysis failed', [
                'error' => $e->getMessage(),
                'dataType' => $dataType
            ]);
            throw $e;
        }
    }

    // =========================================================================
    //  Private helpers
    // =========================================================================

    /**
     * Build the base Gemini API payload (without tools).
     */
    private function buildPayload(array $contents): array
    {
        return [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048,
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ]
            ]
        ];
    }

    /**
     * Return the Gemini function declarations for Bitrix24 API interaction.
     */
    private function getToolDeclarations(): array
    {
        return [
            'functionDeclarations' => [
                [
                    'name' => 'call_bitrix_api',
                    'description' => 'Call any Bitrix24 REST API method to fetch or modify CRM data. '
                        . 'Use this when you need real-time information that is not available in the provided context. '
                        . 'Common methods: user.get (get user details by ID), crm.lead.get (get a single lead), '
                        . 'crm.lead.list (list leads with filters), crm.deal.list (list deals), '
                        . 'crm.contact.list (list contacts), crm.status.list (get statuses), '
                        . 'crm.lead.fields (get lead field definitions).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'method' => [
                                'type' => 'string',
                                'description' => 'The Bitrix24 REST API method to call. '
                                    . 'Examples: user.get, crm.lead.get, crm.lead.list, crm.deal.list, '
                                    . 'crm.contact.get, crm.contact.list, crm.status.list, crm.lead.fields, '
                                    . 'crm.activity.list, department.get',
                            ],
                            'params' => [
                                'type' => 'object',
                                'description' => 'Parameters to pass to the Bitrix24 API method. '
                                    . 'For user.get use {"ID": 118}. '
                                    . 'For crm.lead.list use {"filter": {...}, "select": [...], "order": {...}}. '
                                    . 'For crm.lead.get use {"ID": 12345}.',
                            ],
                        ],
                        'required' => ['method'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Extract functionCall parts from a Gemini response.
     *
     * @return array<array{name: string, args: array}>
     */
    private function extractFunctionCalls(array $parts): array
    {
        $calls = [];
        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $calls[] = [
                    'name' => $part['functionCall']['name'],
                    'args' => $part['functionCall']['args'] ?? [],
                ];
            }
        }
        return $calls;
    }

    /**
     * Execute a Bitrix24 API call requested by Gemini.
     */
    private function executeBitrixCall(string $functionName, array $args): array
    {
        if ($functionName !== 'call_bitrix_api') {
            return ['error' => "Unknown function: {$functionName}"];
        }

        $method = $args['method'] ?? null;
        $params = $args['params'] ?? [];

        if (!$method) {
            return ['error' => 'Missing required parameter: method'];
        }

        // Security: Only allow read methods (prevent writes from AI)
        $allowedPrefixes = [
            'user.get', 'user.search',
            'crm.lead.get', 'crm.lead.list', 'crm.lead.fields', 'crm.lead.productrows.list',
            'crm.deal.get', 'crm.deal.list', 'crm.deal.fields', 'crm.deal.productrows.list',
            'crm.contact.get', 'crm.contact.list', 'crm.contact.fields',
            'crm.company.get', 'crm.company.list',
            'crm.status.list',
            'crm.activity.list', 'crm.activity.get',
            'crm.product.list', 'crm.product.get',
            'crm.category.list',
            'crm.timeline.comment.list',
            'department.get',
            'crm.enum.fields',
        ];

        $isAllowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (strcasecmp($method, $prefix) === 0) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            Log::warning('Gemini attempted blocked Bitrix24 method', [
                'method' => $method,
            ]);
            return ['error' => "Method '{$method}' is not allowed for AI access. Only read-only CRM methods are permitted."];
        }

        try {
            Log::info('Executing Bitrix24 API call from Gemini', [
                'method' => $method,
                'params' => $params,
            ]);

            $result = $this->bitrixClient->call($method, $params);

            // Truncate very large responses to avoid exceeding token limits
            $resultJson = json_encode($result);
            if (strlen($resultJson) > 30000) {
                // If result is too large, only return first items
                if (isset($result['result']) && is_array($result['result'])) {
                    $result['result'] = array_slice($result['result'], 0, 20);
                    $result['_truncated'] = true;
                    $result['_note'] = 'Result was truncated to 20 items to fit token limits. Use filters to narrow down results.';
                }
            }

            return ['success' => true, 'data' => $result];

        } catch (\Exception $e) {
            Log::error('Bitrix24 API call from Gemini failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            return ['error' => "Bitrix24 API call failed: " . $e->getMessage()];
        }
    }

    /**
     * Build system context for CRM-specific conversations
     */
    private function buildSystemContext(array $context): string
    {
        $contextParts = [
            "You are a helpful CRM assistant for Bitrix24 specifically designed to help with lead management and sales analytics."
        ];

        // If we have a BitrixClient, tell the model it can use function calling
        if ($this->bitrixClient) {
            $contextParts[] = "IMPORTANT: You have access to the Bitrix24 REST API via the call_bitrix_api function. "
                . "When the user asks about specific users, leads, deals, contacts, or any CRM entity that is NOT in the provided context below, "
                . "you MUST call the API to fetch the data instead of saying you cannot look it up. "
                . "For example, to find user details, call user.get with {\"ID\": <user_id>}. "
                . "To search leads by criteria, call crm.lead.list with appropriate filters. "
                . "Always prefer fetching real data over telling the user to look it up themselves.";
        }

        if (!empty($context['company_name'])) {
            $contextParts[] = "You are assisting with company: {$context['company_name']}";
        }

        if (!empty($context['lead_sources'])) {
            $contextParts[] = "Lead Sources (Mapping of ID to Name):\n" . json_encode($context['lead_sources'], JSON_PRETTY_PRINT);
        }

        if (!empty($context['recent_leads'])) {
            $contextParts[] = "Here are the most recent leads in the CRM (newest first):\n" . json_encode($context['recent_leads'], JSON_PRETTY_PRINT);
        }

        if (!empty($context['report_summary'])) {
            $contextParts[] = "Here is the 30-day CRM performance summary:\n" . json_encode($context['report_summary'], JSON_PRETTY_PRINT);
        }

        if (!empty($context['report_data'])) {
            $contextParts[] = "Here is the current report data context:\n" . json_encode($context['report_data'], JSON_PRETTY_PRINT);
        }

        if (!empty($context['chat_history'])) {
            $historyPrompt = "Here is the previous chat conversation history for context:\n";
            foreach ($context['chat_history'] as $msg) {
                $roleName = $msg['role'] === 'user' ? 'User' : 'Assistant';
                $historyPrompt .= "- {$roleName}: {$msg['content']}\n";
            }
            $contextParts[] = $historyPrompt;
        }

        $contextParts[] = "Guidelines:";
        $contextParts[] = "- Answer questions about leads, activities, sales performance, and team metrics using the provided CRM context.";
        $contextParts[] = "- When asked about the latest lead, look at the first item in the 'recent_leads' list and display its title, date, source name (mapped from lead_sources), and status.";
        $contextParts[] = "- CRITICAL: Do NOT use any markdown characters, stars, or asterisks (such as **bold** or *italic* or starting list items with * or -). Write strictly in clear, plain text. For bolding, capitalize words if necessary but do not use markdown syntax. Use normal newlines for bullet points.";
        $contextParts[] = "- When you need data you don't have, USE the call_bitrix_api function to fetch it from Bitrix24 in real time.";
        $contextParts[] = "- Keep responses concise and actionable";
        $contextParts[] = "- If you don't know or don't have data, say so clearly";
        $contextParts[] = "- Focus on helping improve sales operations";

        return implode("\n\n", $contextParts);
    }

    /**
     * Extract text from Gemini API response
     */
    private function extractTextFromResponse(array $result): string
    {
        if (
            isset($result['candidates'][0]['content']['parts'][0]['text']) &&
            !empty($result['candidates'][0]['content']['parts'][0]['text'])
        ) {
            return $result['candidates'][0]['content']['parts'][0]['text'];
        }

        // Try to find text in any part (not just the first)
        $parts = $result['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (!empty($part['text'])) {
                return $part['text'];
            }
        }

        throw new \Exception('Invalid response format from Gemini API');
    }

    /**
     * Check if the response contains errors
     */
    private function hasErrors(array $result): bool
    {
        return isset($result['error']) || 
               empty($result['candidates']) ||
               (isset($result['candidates'][0]['finishReason']) && 
                $result['candidates'][0]['finishReason'] === 'SAFETY');
    }

    /**
     * Extract error message from response
     */
    private function extractErrorMessage(array $result): string
    {
        if (isset($result['error']['message'])) {
            return $result['error']['message'];
        }

        if (isset($result['candidates'][0]['finishReason']) && 
            $result['candidates'][0]['finishReason'] === 'SAFETY') {
            return 'The response was blocked due to safety filters. Please rephrase your question.';
        }

        return 'Unable to generate response';
    }

    /**
     * Validate API key format
     */
    public static function validateApiKey(string $apiKey): bool
    {
        // Google API keys are typically 39 characters long
        return !empty($apiKey) && strlen($apiKey) >= 20;
    }
}