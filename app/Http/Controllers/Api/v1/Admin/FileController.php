<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\{File,Chapter};
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Storage;

class FileController extends ResponseController
{

    public function getFile(Request $request)
    {
        try{

            $file = File::first();
            if(!$file){
                return $this->sendError('File data not found.', [], 500);
            }
            $file['full_path'] = asset(Storage::url($file->file_path));
            return $this->sendResponse($file, 'File get successfully.', 200);

        } catch (\Exception $e) {
            return $this->sendError('File upload failed.', ['error' => $e->getMessage()], 500);
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

            $path = $file->store('uploads/files', 'public');

            $record = File::create([
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'status' => 1, 
            ]);

            $parser = new Parser();
            $pdfPath = Storage::disk('public')->path($record->file_path);
            $pdf = $parser->parseFile($pdfPath);
            // Get text
            $text = $pdf->getText();

            // Fix hyphenated line breaks
            $text = preg_replace("/-\s*\n\s*/", "", $text);

            // Normalize whitespace
            $text = preg_replace('/\s+/', ' ', $text);

            // Unicode-safe word count
            $wordCount = preg_match_all('/\p{L}+/u', $text);

            // Page count
            $pages = count($pdf->getPages());
            // dd($pages,$wordCount,$text);
            // Update file record
            $record->update([
                'pages' => $pages,
                'words' => $wordCount,
            ]);

            //chapter wise data
            // $chapters = preg_split('/Chapter\s+\d+/i', $text);

            //$content_store = $this->getChapter($text,$record);
            

            return $this->sendResponse($record, 'File uploaded successfully.', 200);

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

    private function getChapter($text,$book)
    {
        
        $units = preg_split(
            '/\b(UNIT\s*[-–—]?\s*(?:[IVX]+|\d+))\b/i',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        // dd($units);
        $finalUnits = [];

        for ($i = 1; $i < count($units); $i +=2) {
            if (!isset($units[$i + 1])) {
                continue; // skip broken unit
            }

            $finalUnits[] = [
                'title' => trim($units[$i]),       // UNIT - I
                'content' => trim($units[$i + 1]),  // Content of UNIT
            ];
        }
        dd($finalUnits);
        foreach ($finalUnits as $index => $unit) {
            Chapter::create([
                'book_id' => $book->id,
                'chapter_number' => $index + 1,
                'chapter_title' => $unit['title'],
                'content' => $unit['content'],
            ]);
        }

    }
}
