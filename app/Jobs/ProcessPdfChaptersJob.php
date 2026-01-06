<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use App\Models\{File,Chapter};
use App\Services\PdfChapterSplitter;
use Illuminate\Support\Str;

class ProcessPdfChaptersJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    protected $fileId;

    /**
     * Create a new job instance.
     */
    public function __construct($fileId)
    {
        $this->fileId = $fileId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $fileRecord = File::find($this->fileId);
        
        if (!$fileRecord) {
            return;
        }
        
        try {
            $splitter = new PdfChapterSplitter();
            $result = $splitter->parseAndSplit($fileRecord->file_path);
            
            // Update stats
            $fileRecord->update([
                'pages' => $result['pages'],
                'words' => $result['words'],
                'status' => 1 
            ]);
            Log::info("PDF Processing completed for file {$this->fileId} with chapters: " . json_encode($result['chapters']));
            // Save Chapters
            foreach ($result['chapters'] as $index => $chapter) {
                Chapter::create([
                    'book_id' => $fileRecord->id,
                    'chapter'       => $chapter['chapter'],
                    'chapter_title' => $chapter['title'],
                    'content'       => $chapter['content'],
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error("PDF Processing failed for file {$this->fileId}: " . $e->getMessage());
            $fileRecord->update(['status' => 3]); // Failed
        }
    }
    
}
