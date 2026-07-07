<?php

namespace App\Services;

use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Storage;

class PdfChapterSplitter
{
    public function parseAndSplit($filePath)
    {
        $parser = new Parser();
        $pdfPath = Storage::disk('public')->path($filePath);
        $pdf = $parser->parseFile($pdfPath);
        
        $text = $pdf->getText();
        
        // Metadata
        $pages = count($pdf->getPages());
        
        // Cleanup text
        $text = preg_replace("/-\s*\n\s*/", "", $text);
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Word count
        $wordCount = preg_match_all('/\p{L}+/u', $text);
        
        // Split logic
        $chapters = $this->splitIntoChapters($text, $pdf);
        
        return [
            'pages' => $pages,
            'words' => $wordCount,
            'chapters' => $chapters
        ];
    }
    
    private function splitIntoChapters($text, $pdf)
    {
        $pages = $pdf->getPages();
        
        $tempChapters = [];
        $currentChapterHeader = null;
        $currentChapterTitle = null;
        $currentContent = "";
        // Support Chapter, Unit, Lesson as per original requirement
        $chapterPattern = '/^(?:CHAPTER|UNIT|LESSON)\s+([0-9IVX]+)/i';
        
       foreach ($pages as $page) {
            $pageText = $page->getText();
            
            // --- STEP 1: TOC SKIP (only a genuine Table of Contents) ---
            // Skip only real TOC pages: either the phrase "table of contents"
            // appears, or a standalone "Contents" heading sits in the first
            // few lines. Matching the bare word "contents" ANYWHERE (the old
            // rule) wrongly dropped real body pages that merely mention it
            // (e.g. "the contents of the blood"), losing content.
            $firstLines = array_slice(preg_split("/\r\n|\n|\r/", trim($pageText)), 0, 6);
            $looksLikeToc = preg_match('/table\s+of\s+contents/i', $pageText)
                || preg_grep('/^\s*contents\s*$/i', $firstLines);
            if ($looksLikeToc) {
                continue;
            }

            $lines = explode("\n", trim($pageText));
            $foundNewChapter = false;
            $tempHeader = null;
            $tempTitle = null;

            foreach ($lines as $index => $line) {
                // Log::info("Line: " . $line);
                $trimmedLine = trim($line);
                if ($trimmedLine === '' || is_numeric($trimmedLine)) continue;

                if (preg_match($chapterPattern, $trimmedLine)) {
                    $foundNewChapter = true;
                    $tempHeader = $trimmedLine;
                    
                    for ($j = $index + 1; $j < count($lines); $j++) {
                        if (trim($lines[$j]) !== '') {
                            $tempTitle = trim($lines[$j]);
                            break;
                        }
                    }
                    break;
                }
                if ($index > 5) break; 
            }
            // Log::info("Found New Chapter: " . $foundNewChapter);
            if ($foundNewChapter) {
                if ($currentChapterHeader) {
                    $tempChapters[] = [
                        'chapter' => $currentChapterHeader,
                        'title'   => $currentChapterTitle,
                        'content' => trim($currentContent)
                    ];
                }
                $currentChapterHeader = $tempHeader;
                $currentChapterTitle = $tempTitle;
                $currentContent = $this->excludeHeaderLines($pageText, [$tempHeader, $tempTitle]);
            } else {
                if ($currentChapterHeader) {
                    $currentContent .= "\n" . $pageText;
                }
            }
        }

        // Capture the last chapter
        if ($currentChapterHeader) {
            $tempChapters[] = [
                'chapter' => $currentChapterHeader,
                'title'   => $currentChapterTitle,
                'content' => trim($currentContent)
            ];
        }


        // We only keep Main chapters that have a significant amount of text like Chapter 1.
        // TOC items usually only have a more characters of "Chapter1--abcdefr zdfsaf" 
        
        $finalChapters = array_filter($tempChapters, function($ch) {
            return strlen($ch['chapter']) < 15; // Adjust 15 based on your PDF's density
        });
        
        return $finalChapters;
    }

    private function excludeHeaderLines($text, $toRemove)
    {
        foreach ($toRemove as $line) {
            if (!$line) continue;
            $text = str_replace($line, "", $text);
        }
        return trim($text);
    }
}
