<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\FileUpload; // Assuming your FileUpload model exists
use App\Models\Product;    // Assuming your Product model exists and has a 'unique_key' column
use Illuminate\Support\Facades\Log;
use SplFileObject; // For efficient CSV parsing

class ProcessCsvUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $fileUploadId;
    protected $filePath;

    /**
     * Create a new job instance.
     *
     * @param int $fileUploadId The ID of the FileUpload record associated with this job.
     * @param string $filePath The absolute path to the uploaded CSV file on the server.
     */
    public function __construct(int $fileUploadId, string $filePath)
    {
        $this->fileUploadId = $fileUploadId;
        $this->filePath = $filePath;
    }

    /**
     * Execute the job.
     * This method contains the core logic for parsing the CSV, cleaning data,
     * and performing UPSERT operations on the database.
     */
    public function handle(): void
    {
        // Retrieve the FileUpload record to update its status throughout the process.
        $fileUpload = FileUpload::find($this->fileUploadId);

        // If the FileUpload record is not found, log an error and exit.
        if (!$fileUpload) {
            Log::error("ProcessCsvUpload: FileUpload record not found for ID: {$this->fileUploadId}. Aborting job.");
            return;
        }

        try {
            // Update the status of the FileUpload record to 'processing'.
            $fileUpload->update(['status' => 'processing']);
            Log::info("ProcessCsvUpload: Started processing file '{$fileUpload->file_name}' (ID: {$this->fileUploadId}).");

            // Open the CSV file using SplFileObject for efficient row-by-row processing.
            // 'r' mode for reading.
            $file = new SplFileObject($this->filePath, 'r');
            // Set flags for CSV parsing:
            // - READ_CSV: Parse lines as CSV fields.
            // - SKIP_EMPTY: Skip empty lines.
            // - READ_AHEAD: Read ahead to optimize performance (useful for larger files).
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

            $header = []; // To store CSV header columns
            $firstRow = true; // Flag to identify the header row
            $processedRows = 0;
            $skippedRows = 0;

            // Iterate over each row in the CSV file.
            foreach ($file as $row) {
                // Skip completely empty rows (e.g., just commas or empty strings).
                if (empty(array_filter($row))) {
                    $skippedRows++;
                    continue;
                }

                /**
                 * Requirement: Clean up any non-UTF-8 characters.
                 *
                 * This `array_map` applies `mb_convert_encoding` to each cell.
                 * `mb_convert_encoding($value, 'UTF-8', 'UTF-8')` is a common technique
                 * to strip out invalid UTF-8 byte sequences. If your source CSV might
                 * be in a different encoding (e.g., ISO-8859-1), you would change
                 * the third argument (from_encoding) accordingly, e.g., `iconv('ISO-8859-1', 'UTF-8//IGNORE', $value)`.
                 * For general "cleanup", 'UTF-8' to 'UTF-8' works by removing malformed chars.
                 */
                $cleanedRow = array_map(function($value) {
                    // Ensure value is a string before processing
                    if (!is_string($value)) {
                        $value = (string) $value;
                    }
                    // Remove or replace invalid UTF-8 characters.
                    // The '//IGNORE' suffix can be used with iconv to discard invalid characters.
                    // For mb_convert_encoding, converting from UTF-8 to UTF-8 effectively removes invalid sequences.
                    return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }, $row);

                // Process the first row as the header.
                if ($firstRow) {
                    // Trim whitespace from header columns for robust matching
                    $header = array_map('trim', $cleanedRow);
                    // Convert header to uppercase to make matching case-insensitive
                    $header = array_map('strtoupper', $header);
                    $firstRow = false;
                    continue; // Move to the next row (actual data)
                }

                // Ensure the data row has the same number of columns as the header.
                // This prevents `array_combine` errors and helps identify malformed rows.
                if (count($cleanedRow) !== count($header)) {
                    Log::warning("ProcessCsvUpload: Skipping malformed row in file '{$fileUpload->file_name}' (ID: {$this->fileUploadId}). Column count mismatch. Row: " . implode(',', $cleanedRow));
                    $skippedRows++;
                    continue;
                }

                // Combine header with the current row data to create an associative array.
                $data = array_combine($header, $cleanedRow);

                // Map CSV data to your Product model's fillable attributes.
                // Use null coalescing operator (??) to safely access array keys,
                // providing null if the key doesn't exist to prevent errors.
                // Ensure 'PIECE_PRICE' is cast to float for numerical integrity.
                $productData = [
                    'unique_key' => $data['UNIQUE_KEY'] ?? null, // This is your unique identifier
                    'product_title' => $data['PRODUCT_TITLE'] ?? null,
                    'product_description' => $data['PRODUCT_DESCRIPTION'] ?? null,
                    'style_number' => $data['STYLE#'] ?? null, // Assuming '#' is allowed in header or you map it
                    'sanmar_mainframe_color' => $data['SANMAR_MAINFRAME_COLOR'] ?? null,
                    'size' => $data['SIZE'] ?? null,
                    'color_name' => $data['COLOR_NAME'] ?? null,
                    'piece_price' => isset($data['PIECE_PRICE']) ? (float) $data['PIECE_PRICE'] : null,
                    // Add any other fields from your CSV that map to your Product model
                ];

                /**
                 * Requirement: The file upload must be idempotent (no duplicate entries for data).
                 * Requirement: UPSERT the entry / update affected rows instead of creating new.
                 *
                 * This is achieved by using `Product::updateOrCreate()`.
                 * It attempts to find a Product record where 'unique_key' matches the value from the CSV.
                 * If found, it updates that existing record with `$productData`.
                 * If not found, it creates a new record with `$productData`.
                 * This ensures idempotency for the actual product data.
                 */
                if (!empty($productData['unique_key'])) {
                    try {
                        Product::updateOrCreate(
                            ['unique_key' => $productData['unique_key']], // Attributes to find the record
                            $productData                                 // Attributes to create or update
                        );
                        $processedRows++;
                    } catch (\Exception $e) {
                        Log::error("ProcessCsvUpload: Failed to UPSERT product with UNIQUE_KEY '{$productData['unique_key']}' in file '{$fileUpload->file_name}': " . $e->getMessage());
                        $skippedRows++;
                    }
                } else {
                    // Log a warning if a row is skipped due to a missing unique key.
                    Log::warning("ProcessCsvUpload: Skipping row with empty or missing 'UNIQUE_KEY' in file '{$fileUpload->file_name}': " . implode(',', $cleanedRow));
                    $skippedRows++;
                }
            }

            // Update the status of the FileUpload record to 'completed' after successful processing.
            $fileUpload->update(['status' => 'completed']);
            Log::info("ProcessCsvUpload: CSV file '{$fileUpload->file_name}' (ID: {$this->fileUploadId}) processed successfully. Processed rows: {$processedRows}, Skipped rows: {$skippedRows}.");

        } catch (\Exception $e) {
            // If any exception occurs during processing, update the status to 'failed'.
            $fileUpload->update(['status' => 'failed']);
            Log::error("ProcessCsvUpload: Error processing CSV file '{$fileUpload->file_name}' (ID: {$this->fileUploadId}): " . $e->getMessage() . " on line " . $e->getLine());
        } finally {
            // Clean up: Delete the uploaded file from storage after it has been processed (or failed).
            // This prevents accumulation of old CSV files.
            if (file_exists($this->filePath)) {
                unlink($this->filePath);
                Log::info("ProcessCsvUpload: Deleted temporary file: {$this->filePath}");
            }
        }
    }

    /**
     * Handle a job failure.
     * This method is called if the job fails after multiple retries (if configured).
     * It ensures the FileUpload status is marked as 'failed'.
     *
     * @param \Throwable $exception The exception that caused the job to fail.
     */
    public function failed(\Throwable $exception): void
    {
        // Find the FileUpload record and update its status to 'failed'.
        $fileUpload = FileUpload::find($this->fileUploadId);
        if ($fileUpload) {
            $fileUpload->update(['status' => 'failed']);
            Log::error("ProcessCsvUpload: Job failed for FileUpload ID: {$this->fileUploadId}. File: '{$fileUpload->file_name}'. Error: " . $exception->getMessage());
        } else {
            Log::error("ProcessCsvUpload: Job failed for unknown FileUpload ID: {$this->fileUploadId}. Error: " . $exception->getMessage());
        }
        // No need to unlink here as the `finally` block in `handle` will cover it.
    }
}
