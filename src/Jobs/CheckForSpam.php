<?php

namespace DevDojo\Chatter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use DevDojo\Chatter\Models\Models;
use DevDojo\Chatter\Models\SpamCheck;
use Illuminate\Support\Facades\Log;
use DevDojo\Chatter\Services\OpenAIService;
class CheckForSpam implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $type;
    protected $id;
    protected $content;
    protected $title;
    protected $openAIService;

    public function __construct(string $type, int $id, string $content, ?string $title = null)
    {
        $this->type = $type;
        $this->id = $id;
        $this->content = $content;
        $this->title = $title;
        $this->openAIService = new OpenAIService();
    }

    public function handle()
    {
        $startTime = microtime(true);
        
        $spamCheck = SpamCheck::create([
            'checkable_type' => $this->type,
            'checkable_id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'status' => 'processing'
        ]);

        try {
            $checkContent = $this->type === 'discussion' 
                ? "{$this->title}\n{$this->content}"
                : $this->content;

            $result = $this->runSpamCheck($checkContent);
            
            $spamCheck->update([
                'is_spam' => $result['is_spam'],
                'spam_reason' => $result['reason'],
                'metadata' => $result['metadata'] ?? null,
                'status' => 'completed',
                'completed_at' => now(),
                'processing_time' => microtime(true) - $startTime
            ]);

            if ($result['is_spam']) {
                if ($this->type === 'discussion') {
                    $discussion = Models::discussion()->find($this->id);
                    if ($discussion) {
                        $discussion->posts()->delete();
                        $discussion->delete();
                    }
                } else {
                    $post = Models::post()->find($this->id);
                    if ($post) {
                        $post->delete();
                    }
                }
            }
        } catch (\Exception $e) {
            $spamCheck->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now(),
                'processing_time' => microtime(true) - $startTime
            ]);
            
            Log::error('Spam check failed', [
                'error' => $e->getMessage(),
                'type' => $this->type,
                'id' => $this->id
            ]);

            report($e);
        }
    }

    private function runSpamCheck($content)
    {
        $response = $this->openAIService->chatCompletions([
            ["role" => "system", "content" => "You are a smart spam detection system moderating a discussion board where insurance professionals connect with each other trying to find coverage options for hard to place markets, or other insurance related topics. 

Important context: Insurance professionals commonly share their contact information, company affiliations, and roles to facilitate business connections. This is normal and encouraged behavior, not spam.

Evaluate content for the following:
1. Legitimate content includes:
- Insurance professionals introducing themselves and their companies
- Sharing professional contact information and websites
- Offering to help with specific insurance markets
- Appointment/carrier access discussions

2. Actual spam includes:
- Cryptocurrency/investment scams
- Adult content or dating services
- Non-insurance related products/services
- Generic marketing content unrelated to insurance
- Multiple repeated identical posts
- Links to known malicious websites
- Content promoting illegal services

Return a valid JSON object with the following keys: {is_spam, reason}. is_spam must be a boolean, reason should be a concise string that explains why the content is spam or not in one or two sentences.

Example 1:
```
Looking for coverage for an Eco Lodge in Louisiana. Lodges will be over water.
```
Example 1 Response:
```
{\"is_spam\": false, \"reason\": \"The content is a legitimate inquiry about insurance coverage for a specific type of property, which is relevant to the discussion board's purpose.\"}
```

Example 2:
```
Hi, I'm John from ABC Insurance. We specialize in commercial property. Email me at john@abcins.com for appointments.
```
Example 2 Response:
```
{\"is_spam\": false, \"reason\": \"This is a legitimate introduction from an insurance professional sharing relevant contact information for business networking purposes.\"}
```

Example 3:
```
MAKE MONEY FAST! Bitcoin investment opportunity! Contact crypto_expert@scam.com to double your money!
```
Example 3 Response:
```
{\"is_spam\": true, \"reason\": \"The content promotes cryptocurrency investment schemes unrelated to insurance and shows typical scam characteristics.\"}
```"],
            ["role" => "user", "content" => $content]
        ]);

        if (isset($response['choices'][0]['message']['content'])) {
            $result = json_decode($response['choices'][0]['message']['content'], true);
            $result['metadata'] = [
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0
            ];
        } else {
            $result = [
                'is_spam' => false, 
                'reason' => "Unable to process response from AI.",
            ];
        }

        return $result;
    }
} 