<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>oPrint CSV Uploader</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
        }
        .container {
            max-width: 900px;
        }
        .status-pending { color: #f59e0b; } /* amber-500 */
        .status-processing { color: #3b82f6; } /* blue-500 */
        .status-completed { color: #10b981; } /* emerald-500 */
        .status-failed { color: #ef4444; } /* red-500 */
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="container bg-white p-8 rounded-xl shadow-lg w-full">
        <h2 class="text-3xl font-bold text-center text-gray-800 mb-8">CSV Uploader</h2>

        @if (session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
                <strong class="font-bold">Success!</strong>
                <span class="block sm:inline">{{ session('success') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
                <strong class="font-bold">Whoops!</strong>
                <span class="block sm:inline">There were some problems with your upload.</span>
                <ul class="mt-3 list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('upload.csv') }}" method="POST" enctype="multipart/form-data" class="mb-10 p-6 border border-gray-200 rounded-lg bg-gray-50">
            @csrf
            <!-- Modified structure to align with mockup -->
            <div class="flex justify-between items-center mb-4">
                <label for="csv_file_hidden" class="block text-sm font-medium text-gray-700">Select file/Drag and drop</label>
                <button type="submit" class="py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                    Upload File
                </button>
            </div>

            <!-- The actual file input is hidden and triggered by the drag-and-drop area -->
            <input id="csv_file_hidden" name="csv_file" type="file" class="sr-only">

            <div id="drop-area" class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md w-full cursor-pointer hover:border-indigo-500 transition duration-150 ease-in-out">
                <div class="space-y-1 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m-4-4v4m-4-4V12a4 4 0 014-4h4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div class="flex text-sm text-gray-600 justify-center">
                        <p class="font-medium text-indigo-600 hover:text-indigo-500">Upload a file</p>
                        <p class="pl-1">or drag and drop</p>
                    </div>
                    <p class="text-xs text-gray-500">CSV or TXT up to 2MB</p>
                    <p id="file-name-display" class="text-sm text-gray-700 mt-2"></p>
                </div>
            </div>
        </form>

        <h3 class="text-2xl font-semibold text-gray-800 mb-6">Recent Uploads</h3>
        <div class="overflow-x-auto shadow-md rounded-lg">
            <table class="min-w-full divide-y divide-gray-200" id="uploads-table">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Upload Time</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">File Name</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach ($uploads as $upload)
                        <tr id="upload-row-{{ $upload->id }}">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $upload->created_at->format('M d, Y H:i A') }} ({{ $upload->created_at->diffForHumans() }})</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $upload->file_name }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <span class="status-{{ $upload->status }}">{{ ucfirst($upload->status) }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Function to fetch and update upload statuses
        function fetchUploadStatus() {
            fetch('/uploads/status')
                .then(response => response.json())
                .then(data => {
                    const tableBody = document.querySelector('#uploads-table tbody');
                    tableBody.innerHTML = ''; // Clear existing rows

                    data.forEach(upload => {
                        const row = document.createElement('tr');
                        row.id = `upload-row-${upload.id}`;
                        row.innerHTML = `
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${new Date(upload.created_at).toLocaleString()} (${timeAgo(upload.created_at)})</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${upload.file_name}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <span class="status-${upload.status}">${upload.status.charAt(0).toUpperCase() + upload.status.slice(1)}</span>
                            </td>
                        `;
                        tableBody.appendChild(row);
                    });
                })
                .catch(error => console.error('Error fetching upload status:', error));
        }

        // Helper function to calculate time ago
        function timeAgo(dateString) {
            const now = new Date();
            const past = new Date(dateString);
            const seconds = Math.floor((now - past) / 1000);

            let interval = seconds / 31536000;
            if (interval > 1) { return Math.floor(interval) + " years ago"; }
            interval = seconds / 2592000;
            if (interval > 1) { return Math.floor(interval) + " months ago"; }
            interval = seconds / 86400;
            if (interval > 1) { return Math.floor(interval) + " days ago"; }
            interval = seconds / 3600;
            if (interval > 1) { return Math.floor(interval) + " hours ago"; }
            interval = seconds / 60;
            if (interval > 1) { return Math.floor(interval) + " minutes ago"; }
            return Math.floor(seconds) + " seconds ago";
        }

        // Set up polling for upload status
        setInterval(fetchUploadStatus, 5000); // Fetch every 5 seconds

        // Initial fetch on page load
        document.addEventListener('DOMContentLoaded', fetchUploadStatus);

        // --- Drag and Drop / Custom File Input Handling ---
        const dropArea = document.getElementById('drop-area');
        const fileInput = document.getElementById('csv_file_hidden');
        const fileNameDisplay = document.getElementById('file-name-display');

        // Prevent default drag behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false); // For entire page
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        // Highlight drop area when dragging over
        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, () => dropArea.classList.add('border-indigo-500'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, () => dropArea.classList.remove('border-indigo-500'), false);
        });

        // Handle dropped files
        dropArea.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files; // Assign dropped files to the hidden input
            displayFileName(files);
        }

        // Handle file selection via click
        dropArea.addEventListener('click', () => fileInput.click());

        // Update file name display when file is selected
        fileInput.addEventListener('change', (e) => {
            displayFileName(e.target.files);
        });

        function displayFileName(files) {
            if (files.length > 0) {
                fileNameDisplay.textContent = `Selected: ${files[0].name}`;
            } else {
                fileNameDisplay.textContent = '';
            }
        }
    </script>
</body>
</html>
