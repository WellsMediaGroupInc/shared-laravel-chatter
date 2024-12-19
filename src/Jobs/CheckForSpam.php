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
            ["role" => "system", "content" => "You are a smart spam detection system moderating a discussion board where insurance professionals connect with each other trying to find coverage options for hard to place markets, or other insurance related topics. You will be given a piece of content and you will need to determine if it is spam or not. Return a valid JSON object with the following keys: {is_spam, reason}. is_spam must be a boolean, reason should be a concise string that explains why the content is spam or not in one or two sentences.\n\nExample 1:\n```\nLooking for coverage for an Eco Lodge in Louisiana. Lodges will be over water.\n```\nExample 1 Response:\n```\n{\"is_spam\": false, \"reason\": \"The content is a legitimate inquiry about insurance coverage for a specific type of property, which is relevant to the discussion board's purpose.\"}\n```\n\nExample 2:\n```\nAfter weeks of relentless effort, they successfully reclaimed my $900,000 in Bitcoin and returned it to me. I was overjoyed&mdash;not only was my investment restored, but I also felt a sense of triumph over those who thought they could escape unscathed. This experience has heightened my vigilance and commitment to security in cryptocurrency investing, and I am deeply grateful to have had the SOFTWEAR TECH team fighting to protect what belongs to me.<br />For inquiries, feel free to reach out via<br />Email: softweartech5@gmail.com ,<br />Email: softewar.tech@yandex.com<br />Phone : +1 9594003352\n```\nExample 2 Response:\n```\n{\"is_spam\": true, \"reason\": \"The content promotes a service for recovering lost cryptocurrency and includes contact information, which is unrelated to the insurance discussion and appears to be a solicitation for hacking services, which is likely a scam.\"}\n```"],
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