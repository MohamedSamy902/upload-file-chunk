document.addEventListener('DOMContentLoaded', function () {
    window.afuUploadFile = function (options = {}) {
        const fileInput = document.getElementById(options.inputId || 'afu-fileInput');
        const file = fileInput.files[0];
        if (!file) {
            alert('اختر ملف أولاً');
            return;
        }
        const CHUNK_SIZE = options.chunkSize || 5 * 1024 * 1024;
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        let currentChunk = 0;
        const progressBar = document.getElementById(options.progressBarId || 'afu-progress-bar-inner');
        const statusDiv = document.getElementById(options.statusId || 'afu-status');
        function sendChunk(start) {
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const chunk = file.slice(start, end);
            const formData = new FormData();
            formData.append('file', chunk, file.name);
            formData.append('chunkNumber', currentChunk + 1);
            formData.append('totalChunks', totalChunks);
            formData.append('originalName', file.name);
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const xhr = new XMLHttpRequest();
            xhr.open('POST', options.uploadUrl || '/upload', true);
            if (csrfToken) {
                xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
            }
            xhr.onload = function () {
                if (xhr.status === 200) {
                    currentChunk++;
                    const percent = Math.floor((currentChunk / totalChunks) * 100);
                    if (progressBar) progressBar.style.width = percent + '%';
                    if (statusDiv) statusDiv.innerText = `تم رفع ${currentChunk} من ${totalChunks} جزء`;
                    if (currentChunk < totalChunks) {
                        sendChunk(currentChunk * CHUNK_SIZE);
                    } else {
                        if (statusDiv) statusDiv.innerText = 'تم رفع الملف بالكامل!';
                    }
                } else {
                    if (statusDiv) statusDiv.innerText = 'حدث خطأ أثناء الرفع!';
                }
            };
            xhr.onerror = function () {
                if (statusDiv) statusDiv.innerText = 'حدث خطأ في الاتصال!';
            };
            xhr.send(formData);
        }
        sendChunk(0);
    }
});