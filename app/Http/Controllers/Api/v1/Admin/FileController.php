<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\{File};
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Storage;

class FileController extends ResponseController
{
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

            // Word count
            $wordCount = str_word_count($text);

           
            // Page count
            $pages = count($pdf->getPages());
            // dd($pages,$wordCount,$text);
            // Update file record
            $record->update([
                'pages' => $pages,
                'words' => $wordCount,
            ]);

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


}
