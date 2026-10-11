/**
 * VideoShrink Dashboard Client JavaScript
 * Pure Vanilla JS - 100% Local, Offline & Zero CDN Dependencies
 */
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Elements
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('fileInput');
    const browseBtn = document.getElementById('browseBtn');
    const fileDetails = document.getElementById('fileDetails');
    const selectedFilename = document.getElementById('selectedFilename');
    const selectedFileSize = document.getElementById('selectedFileSize');
    const clearFileBtn = document.getElementById('clearFileBtn');
    const startUploadBtn = document.getElementById('startUploadBtn');
    const uploadProgressSection = document.getElementById('uploadProgressSection');
    const uploadStatusText = document.getElementById('uploadStatusText');
    const uploadPercentText = document.getElementById('uploadPercentText');
    const uploadProgressBar = document.getElementById('uploadProgressBar');
    const uploadActionRow = document.getElementById('uploadActionRow');

    const probeMetadataSection = document.getElementById('probeMetadataSection');
    const probeSize = document.getElementById('probeSize');
    const probeDuration = document.getElementById('probeDuration');
    const probeResolution = document.getElementById('probeResolution');
    const probeCodec = document.getElementById('probeCodec');
    const probeFps = document.getElementById('probeFps');
    const probeAudio = document.getElementById('probeAudio');

    const compressionCard = document.getElementById('compressionCard');
    const targetSizePresets = document.getElementById('targetSizePresets');
    const customPresetBtn = document.getElementById('customPresetBtn');
    const customSizeWrapper = document.getElementById('customSizeWrapper');
    const customTargetMb = document.getElementById('customTargetMb');
    const encodingPresetSelect = document.getElementById('encodingPresetSelect');
    const presetExplainer = document.getElementById('presetExplainer');
    const startCompressBtn = document.getElementById('startCompressBtn');

    const activeJobCard = document.getElementById('activeJobCard');
    const activeStatusBadge = document.getElementById('activeStatusBadge');
    const compressionStageText = document.getElementById('compressionStageText');
    const compressionPercentText = document.getElementById('compressionPercentText');
    const compressionProgressBar = document.getElementById('compressionProgressBar');
    const processingTimeDisplay = document.getElementById('processingTimeDisplay');
    const attemptCountDisplay = document.getElementById('attemptCountDisplay');
    const compressionErrorAlert = document.getElementById('compressionErrorAlert');
    const compressionErrorMessage = document.getElementById('compressionErrorMessage');

    const outputSuccessSection = document.getElementById('outputSuccessSection');
    const resOriginalSize = document.getElementById('resOriginalSize');
    const resOutputSize = document.getElementById('resOutputSize');
    const resSavedPercent = document.getElementById('resSavedPercent');
    const resProcessingTime = document.getElementById('resProcessingTime');
    const previewPlayer = document.getElementById('previewPlayer');
    const downloadBtn = document.getElementById('downloadBtn');
    const compressAnotherBtn = document.getElementById('compressAnotherBtn');
    const failureActionsRow = document.getElementById('failureActionsRow');
    const retryJobBtn = document.getElementById('retryJobBtn');
    const deleteFailedJobBtn = document.getElementById('deleteFailedJobBtn');

    const historyTableBody = document.getElementById('historyTableBody');
    const refreshHistoryBtn = document.getElementById('refreshHistoryBtn');

    // State
    let selectedFile = null;
    let currentUploadedVideo = null;
    let pollInterval = null;
    let elapsedTimer = null;
    let jobStartTime = null;
    let selectedTargetMb = 40; // Default 40 MB
    const CHUNK_SIZE = 10 * 1024 * 1024; // 10 MB chunks

    // --- Helpers ---
    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const p = Math.min(Math.floor(Math.log(bytes) / Math.log(1000)), units.length - 1);
        return (bytes / Math.pow(1000, p)).toFixed(2) + ' ' + units[p];
    }

    function formatSeconds(totalSecs) {
        const s = Math.round(totalSecs || 0);
        const mins = Math.floor(s / 60);
        const remSecs = s % 60;
        const hrs = Math.floor(mins / 60);
        const remMins = mins % 60;
        if (hrs > 0) {
            return `${hrs}:${remMins.toString().padStart(2, '0')}:${remSecs.toString().padStart(2, '0')}`;
        }
        return `${remMins}:${remSecs.toString().padStart(2, '0')}`;
    }

    // --- File Drag & Drop ---
    browseBtn.addEventListener('click', (e) => {
        e.preventDefault();
        fileInput.click();
    });

    dropzone.addEventListener('click', (e) => {
        if (e.target !== browseBtn) {
            fileInput.click();
        }
    });

    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('dragover');
        });
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('dragover');
        });
    });

    dropzone.addEventListener('drop', (e) => {
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            handleFileSelected(e.dataTransfer.files[0]);
        }
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files.length > 0) {
            handleFileSelected(fileInput.files[0]);
        }
    });

    function handleFileSelected(file) {
        selectedFile = file;
        selectedFilename.textContent = file.name;
        selectedFileSize.textContent = formatBytes(file.size);
        fileDetails.style.display = 'block';
        dropzone.style.display = 'none';

        // Reset probe & settings state
        probeMetadataSection.style.display = 'none';
        compressionCard.style.opacity = '0.5';
        compressionCard.style.pointerEvents = 'none';
        uploadProgressSection.style.display = 'none';
        uploadActionRow.style.display = 'flex';
        startUploadBtn.disabled = false;
    }

    clearFileBtn.addEventListener('click', () => {
        selectedFile = null;
        fileInput.value = '';
        fileDetails.style.display = 'none';
        dropzone.style.display = 'block';
        probeMetadataSection.style.display = 'none';
        compressionCard.style.opacity = '0.5';
        compressionCard.style.pointerEvents = 'none';
    });

    // --- Chunked Upload ---
    startUploadBtn.addEventListener('click', async () => {
        if (!selectedFile) return;

        startUploadBtn.disabled = true;
        uploadActionRow.style.display = 'none';
        uploadProgressSection.style.display = 'block';

        const totalSize = selectedFile.size;
        const totalChunks = Math.max(1, Math.ceil(totalSize / CHUNK_SIZE));

        try {
            uploadStatusText.textContent = 'Initializing upload session...';
            uploadPercentText.textContent = '0%';
            uploadProgressBar.style.width = '0%';

            // 1. Start Session
            const startResp = await fetch('/upload/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    filename: selectedFile.name,
                    total_size: totalSize,
                    total_chunks: totalChunks,
                }),
            });

            const startData = await startResp.json();
            if (!startResp.ok || !startData.success) {
                throw new Error(startData.message || 'Failed to initialize upload session.');
            }

            const sessionId = startData.session_id;

            // 2. Upload Chunks Sequentially
            for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                const startByte = chunkIndex * CHUNK_SIZE;
                const endByte = Math.min(totalSize, startByte + CHUNK_SIZE);
                const chunkBlob = selectedFile.slice(startByte, endByte);

                uploadStatusText.textContent = `Uploading chunk ${chunkIndex + 1} of ${totalChunks}...`;

                let success = false;
                let attempt = 0;
                while (!success && attempt < 3) {
                    attempt++;
                    try {
                        const formData = new FormData();
                        formData.append('session_id', sessionId);
                        formData.append('chunk_index', chunkIndex);
                        formData.append('chunk_file', chunkBlob, `chunk_${chunkIndex}.part`);

                        const chunkResp = await fetch('/upload/chunk', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: formData,
                        });

                        const chunkData = await chunkResp.json();
                        if (chunkResp.ok && chunkData.success) {
                            success = true;
                        } else {
                            if (attempt >= 3) throw new Error(chunkData.message || `Chunk ${chunkIndex + 1} failed.`);
                        }
                    } catch (err) {
                        if (attempt >= 3) throw err;
                        await new Promise(r => setTimeout(r, 1000)); // Short pause before retry
                    }
                }

                const percent = Math.round(((chunkIndex + 1) / totalChunks) * 100);
                uploadPercentText.textContent = `${percent}%`;
                uploadProgressBar.style.width = `${percent}%`;
            }

            // 3. Assemble and Validate Complete File
            uploadStatusText.textContent = 'Assembling and verifying video with FFprobe...';

            const completeResp = await fetch('/upload/complete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ session_id: sessionId }),
            });

            const completeData = await completeResp.json();
            if (!completeResp.ok || !completeData.success) {
                throw new Error(completeData.message || 'Failed to assemble video.');
            }

            // Upload Success!
            uploadStatusText.textContent = 'Upload and validation complete!';
            currentUploadedVideo = completeData.video;

            // Display probe info
            probeSize.textContent = currentUploadedVideo.formatted_size;
            probeDuration.textContent = currentUploadedVideo.formatted_duration;
            probeResolution.textContent = `${currentUploadedVideo.width} × ${currentUploadedVideo.height}`;
            probeCodec.textContent = `${currentUploadedVideo.codec} (${currentUploadedVideo.format})`;
            probeFps.textContent = currentUploadedVideo.fps ? `${currentUploadedVideo.fps} fps` : 'Unknown';
            probeAudio.textContent = currentUploadedVideo.has_audio ? 'Yes (Audio stream present)' : 'None (Video only)';
            probeMetadataSection.style.display = 'block';

            // Unlock Compression card
            compressionCard.style.opacity = '1';
            compressionCard.style.pointerEvents = 'auto';

            // Refresh history
            refreshHistory();
        } catch (err) {
            alert('Upload Error: ' + err.message);
            uploadStatusText.textContent = 'Upload failed.';
            uploadActionRow.style.display = 'flex';
            startUploadBtn.disabled = false;
        }
    });

    // --- Preset Buttons & Custom Input ---
    targetSizePresets.addEventListener('click', (e) => {
        const btn = e.target.closest('.preset-btn');
        if (!btn) return;

        targetSizePresets.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const val = btn.getAttribute('data-value');
        if (val === 'custom') {
            customSizeWrapper.style.display = 'flex';
            selectedTargetMb = parseFloat(customTargetMb.value) || 40;
            customTargetMb.focus();
        } else {
            customSizeWrapper.style.display = 'none';
            selectedTargetMb = parseFloat(val);
        }
    });

    customTargetMb.addEventListener('input', () => {
        selectedTargetMb = parseFloat(customTargetMb.value) || 0;
    });

    // Encoding Preset Explainer
    encodingPresetSelect.addEventListener('change', () => {
        const p = encodingPresetSelect.value;
        const descriptions = {
            'ultrafast': 'Ultra Fast prioritizes encoding speed above all else. Visual quality will be lower.',
            'superfast': 'Super Fast encodes very quickly with modest compression efficiency.',
            'veryfast': 'Very Fast is good for rapid test runs.',
            'faster': 'Faster provides a quicker turnaround than standard.',
            'fast': 'Fast offers a slight speed boost with minimal quality loss.',
            'medium': 'Medium (Default) provides the optimal balance of quality, size, and speed.',
            'slow': 'Slow spends extra CPU time analyzing motion vectors for higher visual fidelity.',
            'slower': 'Slower produces very crisp results but takes noticeably longer.',
            'veryslow': 'Very Slow achieves maximum compression efficiency at the cost of high encoding time.',
        };
        presetExplainer.innerHTML = `<strong>${p.toUpperCase()}</strong>: ${descriptions[p] || ''}`;
    });

    // --- Start Compression ---
    startCompressBtn.addEventListener('click', async () => {
        if (!currentUploadedVideo) return;

        if (!selectedTargetMb || selectedTargetMb <= 0) {
            alert('Please select or specify a valid target size in MB.');
            return;
        }

        const selectedRes = document.querySelector('input[name="targetResolution"]:checked')?.value || 'original';
        const selectedPreset = encodingPresetSelect.value;

        startCompressBtn.disabled = true;

        try {
            const resp = await fetch(`/videos/${currentUploadedVideo.uuid}/compress`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    target_mb: selectedTargetMb,
                    target_resolution: selectedRes,
                    encoding_preset: selectedPreset,
                }),
            });

            const data = await resp.json();
            if (!resp.ok || !data.success) {
                throw new Error(data.message || 'Failed to queue compression.');
            }

            // Switch to Active Job View
            showActiveJobView(currentUploadedVideo.uuid);
        } catch (err) {
            alert('Compression Error: ' + err.message);
            startCompressBtn.disabled = false;
        }
    });

    // --- Active Job UI & Status Polling ---
    function showActiveJobView(uuid) {
        activeJobCard.style.display = 'block';
        activeJobCard.scrollIntoView({ behavior: 'smooth', block: 'start' });

        compressionProgressSection.style.display = 'block';
        outputSuccessSection.style.display = 'none';
        failureActionsRow.style.display = 'none';
        compressionErrorAlert.style.display = 'none';

        compressionStageText.textContent = 'Queued in background queue...';
        compressionPercentText.textContent = '0%';
        compressionProgressBar.style.width = '0%';
        activeStatusBadge.className = 'status-badge status-queued';
        activeStatusBadge.textContent = 'Queued';

        jobStartTime = Date.now();
        startElapsedTimer();
        startStatusPolling(uuid);
    }

    function startElapsedTimer() {
        if (elapsedTimer) clearInterval(elapsedTimer);
        elapsedTimer = setInterval(() => {
            if (jobStartTime) {
                const elapsedSecs = Math.floor((Date.now() - jobStartTime) / 1000);
                processingTimeDisplay.textContent = `Elapsed: ${elapsedSecs}s`;
            }
        }, 1000);
    }

    function stopElapsedTimer() {
        if (elapsedTimer) {
            clearInterval(elapsedTimer);
            elapsedTimer = null;
        }
    }

    function startStatusPolling(uuid) {
        if (pollInterval) clearInterval(pollInterval);

        pollInterval = setInterval(async () => {
            try {
                const resp = await fetch(`/videos/${uuid}/status`, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!resp.ok) return;

                const data = await resp.json();
                if (!data.success || !data.video) return;

                const v = data.video;
                updateJobUI(v);

                if (v.status === 'completed' || v.status === 'failed') {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    stopElapsedTimer();
                    refreshHistory();
                }
            } catch (e) {
                console.error('Polling error:', e);
            }
        }, 2000);
    }

    function updateJobUI(v) {
        activeStatusBadge.className = `status-badge status-${v.status}`;
        activeStatusBadge.textContent = v.status.toUpperCase();
        attemptCountDisplay.textContent = `Attempt ${v.attempt_count || 1} of 3`;

        if (v.status === 'queued') {
            compressionStageText.textContent = v.processing_stage || 'Queued...';
            compressionPercentText.textContent = `${v.progress_percentage}%`;
            compressionProgressBar.style.width = `${v.progress_percentage}%`;
        } else if (v.status === 'processing') {
            compressionStageText.textContent = v.processing_stage || 'Processing...';
            compressionPercentText.textContent = `${v.progress_percentage}%`;
            compressionProgressBar.style.width = `${v.progress_percentage}%`;
        } else if (v.status === 'completed') {
            compressionStageText.textContent = 'Compression successfully completed!';
            compressionPercentText.textContent = '100%';
            compressionProgressBar.style.width = '100%';

            // Populate Results
            resOriginalSize.textContent = v.formatted_original_size;
            resOutputSize.textContent = v.formatted_output_size || '-';
            resSavedPercent.textContent = v.percentage_saved !== null ? `${v.percentage_saved}%` : '0%';
            resProcessingTime.textContent = v.processing_time_seconds !== null ? `${v.processing_time_seconds}s` : 'Done';

            // Set Video Preview Player
            previewPlayer.src = v.preview_url;
            previewPlayer.load();

            // Download Link
            downloadBtn.href = v.download_url;

            outputSuccessSection.style.display = 'block';
            startCompressBtn.disabled = false;
        } else if (v.status === 'failed') {
            compressionErrorMessage.textContent = v.error_message || 'Compression failed.';
            compressionErrorAlert.style.display = 'flex';
            failureActionsRow.style.display = 'flex';
            retryJobBtn.setAttribute('data-uuid', v.uuid);
            deleteFailedJobBtn.setAttribute('data-uuid', v.uuid);
            startCompressBtn.disabled = false;
        }
    }

    // Compress another button
    compressAnotherBtn.addEventListener('click', () => {
        activeJobCard.style.display = 'none';
        clearFileBtn.click();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // Retry Active Button
    retryJobBtn.addEventListener('click', async () => {
        const uuid = retryJobBtn.getAttribute('data-uuid');
        if (!uuid) return;
        await triggerRetry(uuid);
    });

    // Delete Failed Button
    deleteFailedJobBtn.addEventListener('click', async () => {
        const uuid = deleteFailedJobBtn.getAttribute('data-uuid');
        if (!uuid) return;
        if (confirm('Delete this video?')) {
            await triggerDelete(uuid);
            activeJobCard.style.display = 'none';
        }
    });

    // --- History Actions ---
    refreshHistoryBtn.addEventListener('click', refreshHistory);

    historyTableBody.addEventListener('click', async (e) => {
        const previewBtn = e.target.closest('.view-preview-btn');
        if (previewBtn) {
            const uuid = previewBtn.getAttribute('data-uuid');
            showExistingPreview(uuid);
            return;
        }

        const retryBtn = e.target.closest('.retry-table-btn');
        if (retryBtn) {
            const uuid = retryBtn.getAttribute('data-uuid');
            await triggerRetry(uuid);
            return;
        }

        const deleteBtn = e.target.closest('.delete-table-btn');
        if (deleteBtn) {
            const uuid = deleteBtn.getAttribute('data-uuid');
            if (confirm('Are you sure you want to delete this video?')) {
                await triggerDelete(uuid);
            }
            return;
        }
    });

    async function triggerRetry(uuid) {
        try {
            const resp = await fetch(`/videos/${uuid}/retry`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
            const data = await resp.json();
            if (resp.ok && data.success) {
                showActiveJobView(uuid);
                refreshHistory();
            } else {
                alert('Retry Error: ' + (data.message || 'Unable to retry.'));
            }
        } catch (err) {
            alert('Retry Error: ' + err.message);
        }
    }

    async function triggerDelete(uuid) {
        try {
            const resp = await fetch(`/videos/${uuid}`, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
            const data = await resp.json();
            if (resp.ok && data.success) {
                const row = document.getElementById(`row-${uuid}`);
                if (row) row.remove();
                refreshHistory();
            } else {
                alert('Delete Error: ' + (data.message || 'Unable to delete.'));
            }
        } catch (err) {
            alert('Delete Error: ' + err.message);
        }
    }

    async function showExistingPreview(uuid) {
        try {
            const resp = await fetch(`/videos/${uuid}/status`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await resp.json();
            if (resp.ok && data.success) {
                showActiveJobView(uuid);
                updateJobUI(data.video);
            }
        } catch (err) {
            console.error(err);
        }
    }

    async function refreshHistory() {
        try {
            const resp = await fetch('/videos', {
                headers: { 'Accept': 'application/json' },
            });
            const data = await resp.json();
            if (!resp.ok || !data.videos) return;

            if (data.videos.length === 0) {
                historyTableBody.innerHTML = `
                    <tr id="emptyHistoryRow">
                        <td colspan="8" class="text-center text-muted">No videos compressed yet. Upload a video above to begin!</td>
                    </tr>
                `;
                return;
            }

            let html = '';
            data.videos.forEach(v => {
                const savedBadge = v.percentage_saved !== null
                    ? `<span class="badge badge-saved">${v.percentage_saved}%</span>`
                    : '-';

                let actionBtns = '';
                if (v.status === 'completed') {
                    actionBtns += `<button type="button" class="btn btn-xs btn-outline view-preview-btn" data-uuid="${v.uuid}">Preview</button> `;
                    actionBtns += `<a href="${v.download_url}" class="btn btn-xs btn-primary">Download</a> `;
                } else if (v.status === 'failed') {
                    actionBtns += `<button type="button" class="btn btn-xs btn-warning retry-table-btn" data-uuid="${v.uuid}">Retry</button> `;
                }
                if (v.status !== 'processing') {
                    actionBtns += `<button type="button" class="btn btn-xs btn-danger delete-table-btn" data-uuid="${v.uuid}">Delete</button>`;
                }

                html += `
                    <tr data-uuid="${v.uuid}" id="row-${v.uuid}">
                        <td class="table-filename" title="${v.original_filename}">${v.original_filename}</td>
                        <td>${v.formatted_duration}</td>
                        <td>${v.formatted_original_size}</td>
                        <td>${v.formatted_target_size || '-'}</td>
                        <td>${v.formatted_output_size || '-'}</td>
                        <td>${savedBadge}</td>
                        <td><span class="status-badge status-${v.status}">${v.status.toUpperCase()}</span></td>
                        <td class="table-actions">${actionBtns}</td>
                    </tr>
                `;
            });
            historyTableBody.innerHTML = html;
        } catch (err) {
            console.error('Failed to refresh history:', err);
        }
    }
});

