<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\{File,Chapter};
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ProcessPdfChaptersJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class FileController extends ResponseController
{

    public function getFile(Request $request)
    {
        try{

            $file = File::first();
            if(!$file){
                return $this->sendError('File data not found.', [], 500);
            }
            // $file['full_path'] = asset(Storage::url($file->file_path));
            $file['full_path'] = Config::get('constant.media_base_url').config('constant.media_base_path').$file->file_path;
            return $this->sendResponse($file, 'File get successfully.', 200);

        } catch (\Exception $e) {
            return $this->sendError('File get failed.', ['error' => $e->getMessage()], 500);
        }
    }
    public function upload(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:pdf', // 10MB
            ]);

            if ($validator->fails()) {
                return $this->sendValidationError($validator->errors());
            }

            $file = $request->file('file');

            
            // Original file name
            $originalName = $file->getClientOriginalName();

            // Define storage path
            $storagePath = 'uploads/files/' . $originalName;
            
            $file->storeAs('uploads/files', $originalName, 'public');

            $record = File::create([
                'original_name' => $originalName,
                'file_path' => $storagePath,
                'mime_type' => $file->getClientMimeType(),
                'status' => 1, // Pending
            ]);

            //only for testing file chapter extraction result
            // $parser = new Parser();
            // $pdfPath = Storage::disk('public')->path($record->file_path);
            // $pdf = $parser->parseFile($pdfPath);
            // $content_store = $this->storeChapter($pdf,$record);

            // Dispatch Job
            ProcessPdfChaptersJob::dispatch($record->id);

            return $this->sendResponse($record, 'File uploaded. Processing started in background.', 200);

        } catch (\Exception $e) {
            return $this->sendError('File upload failed.', ['error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try{

            $file = File::findOrFail($id);

            if (Storage::disk('public')->exists($file->file_path)) {
                Storage::disk('public')->delete($file->file_path);
            }
            $file->delete();

            return $this->sendResponse([], 'File deleted successfully.', 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            
            return $this->sendError('File not found.', [], 404);

        } catch (\Exception $e) {
            
            return $this->sendError('Failed to delete file: ' . $e->getMessage(), [], 500);
        }
        
    }


    /**
     * Helper to remove the header lines from the start of the first page of a chapter
     */
    // private function excludeHeaderLines($text, $toRemove)
    // {
    //     foreach ($toRemove as $line) {
    //         if (!$line) continue;
    //         $text = str_replace($line, "", $text);
    //     }
    //     return trim($text);
    // }

    protected function cleanText($text)
    {
        // Remove multiple newlines and common PDF artifacts like page numbers at the bottom
        return preg_replace('/\n\s*\d+\s*\n/', "\n", $text);
    }

    // private function storeChapter($pdf, $book)
    // {
    //     $pages = $pdf->getPages();
    //     $tempChapters = [];
    //     $currentChapterHeader = null;
    //     $currentChapterTitle = null;
    //     $currentContent = "";
        
    //     $chapterPattern = '/^(?:CHAPTER|UNIT|LESSON)\s+([0-9IVX]+)/i';

    //     foreach ($pages as $page) {
    //         $pageText = $page->getText();
            
    //         // --- STEP 1: STRICT TOC SKIP ---
    //         // If the page contains "Contents" or "Table of Contents", skip processing it
    //         if (preg_match('/contents|table\s+of\s+contents/i', $pageText)) {
    //             continue;
    //         }

    //         $lines = explode("\n", trim($pageText));
    //         $foundNewChapter = false;
    //         $tempHeader = null;
    //         $tempTitle = null;

    //         foreach ($lines as $index => $line) {
    //             // Log::info("Line: " . $line);
    //             $trimmedLine = trim($line);
    //             if ($trimmedLine === '' || is_numeric($trimmedLine)) continue;

    //             if (preg_match($chapterPattern, $trimmedLine)) {
    //                 $foundNewChapter = true;
    //                 $tempHeader = $trimmedLine;
                    
    //                 for ($j = $index + 1; $j < count($lines); $j++) {
    //                     if (trim($lines[$j]) !== '') {
    //                         $tempTitle = trim($lines[$j]);
    //                         break;
    //                     }
    //                 }
    //                 break;
    //             }
    //             if ($index > 5) break; 
    //         }
    //         // Log::info("Found New Chapter: " . $foundNewChapter);
    //         if ($foundNewChapter) {
    //             if ($currentChapterHeader) {
    //                 $tempChapters[] = [
    //                     'chapter' => $currentChapterHeader,
    //                     'title'   => $currentChapterTitle,
    //                     'content' => trim($currentContent)
    //                 ];
    //             }
    //             $currentChapterHeader = $tempHeader;
    //             $currentChapterTitle = $tempTitle;
    //             $currentContent = $this->excludeHeaderLines($pageText, [$tempHeader, $tempTitle]);
    //         } else {
    //             if ($currentChapterHeader) {
    //                 $currentContent .= "\n" . $pageText;
    //             }
    //         }
    //     }

    //     // Capture the last chapter
    //     if ($currentChapterHeader) {
    //         $tempChapters[] = [
    //             'chapter' => $currentChapterHeader,
    //             'title'   => $currentChapterTitle,
    //             'content' => trim($currentContent)
    //         ];
    //     }


    //     // We only keep Main chapters that have a significant amount of text like Chapter 1.
    //     // TOC items usually only have a more characters of "Chapter1--abcdefr zdfsaf" 
        
    //     $finalChapters = array_filter($tempChapters, function($ch) {
    //         return strlen($ch['chapter']) < 15; // Adjust 15 based on your PDF's density
    //     });

    //     // dd($finalChapters);
    //     foreach ($finalChapters as $data) {
    //         Chapter::create([
    //             'book_id'       => $book->id,
    //             'chapter'       => $data['chapter'],
    //             'chapter_title' => $this->cleanText($data['title']),
    //             'content'       => $this->cleanText($data['content']),
               
    //         ]);
    //     }

    //     return count($finalChapters);
    // }
}
