<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChatterSpamChecksTable extends Migration
{
    public function up()
    {
        Schema::create('chatter_spam_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            $table->string('checkable_type');  // 'discussion' or 'post'
            $table->unsignedBigInteger('checkable_id');
            
            $table->text('title')->nullable();
            $table->text('content');
            
            $table->boolean('is_spam')->nullable();
            $table->text('spam_reason')->nullable();  // AI's explanation if spam
            
            $table->timestamp('completed_at')->nullable();
            $table->float('processing_time')->nullable();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['checkable_type', 'checkable_id']);
            $table->index('status');
            $table->index('is_spam');
        });
    }

    public function down()
    {
        Schema::dropIfExists('chatter_spam_checks');
    }
}; 