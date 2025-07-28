<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FileUpload;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ProcessCsvUpload;
use Illuminate\Support\Facades\Log;

class FileUploadController extends Controller
{
    public function index()
    {
        $uploads = FileUpload::latest()->get();
        return view('upload', compact('uploads'));
    }

    public function upload(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('csv_file');

        if (!$file || !$file->isValid()) {
            Log::error("FileUpload: No valid file uploaded or file is not valid. Request IP: " . $request->ip());
            return redirect()->back()->withErrors(['csv_file' => 'No valid file was uploaded.']);
        }

        // --- NEW DIAGNOSTIC STEPS ---
        $tempFilePath = $file->getRealPath();
        if (empty($tempFilePath)) {
            Log::error("FileUpload: getRealPath() returned empty for uploaded file. This often indicates a PHP temporary file upload issue (permissions or size limits).");
            return redirect()->back()->withErrors(['csv_file' => 'The uploaded file could not be processed. Please check PHP temporary file settings.']);
        }

        if (!file_exists($tempFilePath)) {
            Log::error("FileUpload: Temporary uploaded file does not exist at expected path: {$tempFilePath}. Check PHP temporary directory permissions or upload_max_filesize/post_max_size.");
            return redirect()->back()->withErrors(['csv_file' => 'The uploaded file was not found on the server. Please check server configuration.']);
        }

        if (!is_readable($tempFilePath)) {
            Log::error("FileUpload: Temporary uploaded file is not readable at path: {$tempFilePath}. Check PHP temporary directory permissions.");
            return redirect()->back()->withErrors(['csv_file' => 'The uploaded file is not readable by the server. Please check server permissions.']);
        }
        // --- END NEW DIAGNOSTIC STEPS ---


        $fileName = time() . '_' . $file->getClientOriginalName();
        $directory = 'uploads'; // Define the directory

        try {
            // Attempt to store the file
            $relativePath = $file->storeAs($directory, $fileName, 'local'); // Explicitly specify 'local' disk
        } catch (\Exception $e) {
            Log::error("FileUpload: Failed to store file '{$fileName}'. Exception: " . $e->getMessage() . " Temp path used: " . $tempFilePath);
            return redirect()->back()->withErrors(['csv_file' => 'Failed to store the uploaded file due to an internal error. Please check server logs.']);
        }

        // IMPORTANT: Check if storeAs returned a valid path
        if (!$relativePath || !is_string($relativePath) || empty($relativePath)) {
            Log::error("FileUpload: storeAs returned an invalid path for '{$fileName}'. Relative path: " . ($relativePath ?: 'empty/false') . " Temp path used: " . $tempFilePath);
            return redirect()->back()->withErrors(['csv_file' => 'Failed to store the uploaded file. Storage path could not be determined.']);
        }

        // Get the absolute path to the stored file.
        // This is crucial for SplFileObject which needs a real file system path.
        $absolutePath = Storage::disk('local')->path($relativePath);

        // Verify that the absolute path is not empty and the file actually exists
        if (empty($absolutePath) || !file_exists($absolutePath)) {
            Log::error("FileUpload: Absolute path could not be determined or file does not exist after storage. Expected path: {$absolutePath}. Original temp path: " . $tempFilePath);
            return redirect()->back()->withErrors(['csv_file' => 'Failed to locate the uploaded file for processing.']);
        }

        // Create a record in the database for the file upload
        $fileUpload = FileUpload::create([
            'file_name' => $fileName,
            'file_path' => $relativePath, // Store the relative path in DB
            'status' => 'pending',
        ]);

        // Dispatch the job to process the CSV in the background, passing the absolute path
        ProcessCsvUpload::dispatch($fileUpload->id, $absolutePath);

        return redirect()->back()->with('success', 'File uploaded and processing started!');
    }

    public function status()
    {
        $uploads = FileUpload::latest()->get(['id', 'file_name', 'status', 'created_at']);
        return response()->json($uploads);
    }
}