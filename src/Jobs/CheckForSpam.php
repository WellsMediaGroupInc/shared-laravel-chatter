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
use App\Services\OpenAIService;
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
        }
    }

    private function runSpamCheck($content)
    {
        $response = $this->openAIService->chatCompletions([
            ["role" => "system", "content" => "You are a smart spam detection system moderating a discussion board where insurance professionals connect with each other trying to find coverage options for hard to place markets, or other insurance related topics. You will be given a piece of content and you will need to determine if it is spam or not. Return a valid JSON object with the following keys: {is_spam, reason}. is_spam must be a boolean, reason should be a concise string that explains why the content is spam or not in one or two sentences."],
            ["role" => "user", "content" => $content]
        ]);

        $response = json_decode($response, true);

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