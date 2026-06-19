<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class GeminiService
{
    private $apiKey;
    private const API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';
    private const MODEL = 'gemini-flash-latest';

    public function __construct(string $apiKey)
    {
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('Gemini API key is required');
        }
        $this->apiKey = $apiKey;
    }

    /**
     * Generate a response from Gemini based on user question
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
                    'temperature' => 0.7,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 1024,
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

            Log::info('Gemini API Request', [
                'endpoint' => self::API_ENDPOINT,
                'payload' => $payload,
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'X-goog-api-key' => $this->apiKey,
                ])
                ->post(self::API_ENDPOINT, $payload);

            Log::info('Gemini API Response', [
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

    /**
     * Build system context for CRM-specific conversations
     */
    private function buildSystemContext(array $context): string
    {
        $contextParts = [
            "You are a helpful CRM assistant for Bitrix24 specifically designed to help with lead management and sales analytics."
        ];

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

        $contextParts[] = "Guidelines:";
        $contextParts[] = "- Answer questions about leads, activities, sales performance, and team metrics using the provided CRM context.";
        $contextParts[] = "- When asked about the latest lead, look at the first item in the 'recent_leads' list and display its title, date, source name (mapped from lead_sources), and status.";
        $contextParts[] = "- Provide practical advice based on CRM data patterns";
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