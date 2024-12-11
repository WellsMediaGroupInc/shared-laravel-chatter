<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
class OpenAIService
{
    protected $baseUrl;
    protected $apiKey;
    protected $defaultModel;

    public function __construct()
    {
        $this->baseUrl = 'https://api.openai.com/v1';
        $this->apiKey = env('OPENAI_API_KEY');
        $this->defaultModel = 'gpt-4o-mini';
    }

    protected function sendRequest(array $data, string $endpoint)
    {
        Log::info('Sending request to ' . $this->baseUrl . $endpoint);
        Log::info('Data: ' . json_encode($data));

        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        Log::info('Response: ' . json_encode($response));

        return json_decode($response, true);
    }

    public function chatCompletions(array $messages, string $model = null, float $temperature = 0.3)
    {
        $data = [
            'model' => $model ?? $this->defaultModel,
            'temperature' => $temperature,
            'messages' => $messages,
        ];

        return $this->sendRequest($data, '/chat/completions');
    }
}