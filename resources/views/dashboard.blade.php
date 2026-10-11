@extends('layouts.app')

@section('content')
<div class="dashboard-grid">

    <!-- Quality & Performance Notice -->
    <div class="alert alert-info">
        <div class="alert-icon">ℹ️</div>
        <div class="alert-content">
            <strong>Compression Expectations:</strong>
            Compressing to smaller target sizes (like 20 MB or 40 MB) requires lowering video bitrate, which will reduce visual fidelity and sharpness.
            Two-pass CPU encoding is thorough and preserves duration and audio, but processing is not instant. Your computer must remain running while encoding.
        </div>
    </div>

    <!-- Step 1: Upload Card -->
    <div class="card" id="uploadCard">
        <div class="card-header">
            <span class="step-badge">1</span>
            <div>
                <h2>Upload Video</h2>
                <p class="card-desc">Select a video up to 1 GB. Files are uploaded in reliable 10 MB sequential chunks.</p>
            </div>
        </div>
        <div class="card-body">
            <!-- Dropzone -->
            <div class="dropzone" id="dropzone">
                <input type="file" id="fileInput" accept="video/mp4,video/quicktime,video/x-matroska,video/x-msvideo,video/webm,.mp4,.mov,.mkv,.avi,.webm" style="display:none">
                <div class="dropzone-content">
                    <div class="dropzone-icon">📁</div>
                    <div class="dropzone-text">
                        <strong>Drag and drop your video file here</strong>, or <button type="button" class="btn-link" id="browseBtn">browse files</button>
                    </div>
                    <p class="dropzone-hint">Supports MP4, MOV, MKV, AVI, WebM (Max: {{ round($maxUploadSize / 1000000000, 1) }} GB)</p>
                </div>
            </div>

            <!-- Selected File Details -->
            <div id="fileDetails" class="file-details" style="display:none">
                <div class="file-info-row">
                    <div class="file-icon">🎥</div>
                    <div class="file-meta">
                        <span id="selectedFilename" class="file-name">filename.mp4</span>
                        <span id="selectedFileSize" class="file-size">0 MB</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline" id="clearFileBtn">Change</button>
                </div>

                <!-- Upload Progress (Isolated from compression) -->
                <div id="uploadProgressSection" class="progress-section" style="display:none">
                    <div class="progress-labels">
                        <span id="uploadStatusText">Uploading chunk 1 of 1...</span>
                        <span id="uploadPercentText">0%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div id="uploadProgressBar" class="progress-bar-fill upload-fill" style="width: 0%"></div>
                    </div>
                </div>

                <div class="action-row" id="uploadActionRow">
                    <button type="button" class="btn btn-primary" id="startUploadBtn">
                        <span>⬆️ Upload to Local Server</span>
                    </button>
                </div>
            </div>

            <!-- Probed Metadata Preview -->
            <div id="probeMetadataSection" class="probe-card" style="display:none">
                <h3>Source Video Specifications</h3>
                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Original Size</span>
                        <span class="meta-value" id="probeSize">-</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Duration</span>
                        <span class="meta-value" id="probeDuration">-</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Resolution</span>
                        <span class="meta-value" id="probeResolution">-</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Codec / Format</span>
                        <span class="meta-value" id="probeCodec">-</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Frame Rate</span>
                        <span class="meta-value" id="probeFps">-</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Audio Stream</span>
                        <span class="meta-value" id="probeAudio">-</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 2: Compression Settings Card -->
    <div class="card" id="compressionCard" style="opacity: 0.5; pointer-events: none;">
        <div class="card-header">
            <span class="step-badge">2</span>
            <div>
                <h2>Target Output Settings</h2>
                <p class="card-desc">Configure target size, resolution scaling, and H.264 CPU speed preset.</p>
            </div>
        </div>
        <div class="card-body">
            <!-- Target Size Presets -->
            <div class="form-group">
                <label class="form-label">
                    <strong>Target Output Size (MB)</strong>
                    <span class="label-hint">(1 MB = 1,000,000 bytes)</span>
                </label>
                <div class="preset-buttons" id="targetSizePresets">
                    @foreach($targetPresetsMb as $presetMb)
                        <button type="button" class="preset-btn {{ $presetMb === 40 ? 'active' : '' }}" data-value="{{ $presetMb }}">
                            {{ $presetMb }} MB
                        </button>
                    @endforeach
                    <button type="button" class="preset-btn" data-value="custom" id="customPresetBtn">Custom</button>
                </div>
                <div id="customSizeWrapper" class="custom-input-wrapper" style="display:none">
                    <input type="number" id="customTargetMb" min="1" max="5000" placeholder="e.g. 35" class="form-input">
                    <span class="input-unit">MB</span>
                </div>
            </div>

            <!-- Resolution Selection -->
            <div class="form-group">
                <label class="form-label">
                    <strong>Output Resolution</strong>
                    <span class="label-hint">(Preserves aspect ratio, landscape &amp; portrait, no upscaling)</span>
                </label>
                <div class="resolution-options">
                    @foreach($resolutions as $resKey => $resLabel)
                        <label class="radio-card">
                            <input type="radio" name="targetResolution" value="{{ $resKey }}" {{ $resKey === 'original' ? 'checked' : '' }}>
                            <div class="radio-content">
                                <span class="radio-title">{{ $resLabel }}</span>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Encoding Speed Preset -->
            <div class="form-group">
                <label class="form-label" for="encodingPresetSelect">
                    <strong>Encoding Speed Preset</strong>
                    <span class="label-hint">Speed vs. Quality Trade-off</span>
                </label>
                <select id="encodingPresetSelect" class="form-select">
                    @foreach($encodingPresets as $presetKey => $presetDesc)
                        <option value="{{ $presetKey }}" {{ $presetKey === 'medium' ? 'selected' : '' }}>
                            {{ $presetDesc }}
                        </option>
                    @endforeach
                </select>
                <p class="preset-explainer" id="presetExplainer">
                    <strong>Medium</strong> provides the optimal balance of quality, target accuracy, and encoding time.
                    Slower presets produce slightly sharper video at the same target size, but require more CPU processing time.
                </p>
            </div>

            <!-- Submit Compression -->
            <div class="action-row">
                <button type="button" class="btn btn-success btn-lg" id="startCompressBtn">
                    <span>🎬 Compress Video</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Step 3: Active Job & Output Card -->
    <div class="card" id="activeJobCard" style="display:none">
        <div class="card-header">
            <span class="step-badge">3</span>
            <div>
                <h2 id="activeJobHeader">Compression Status</h2>
                <p class="card-desc" id="activeJobSubheader">Two-pass CPU encoding in progress.</p>
            </div>
            <div class="header-status">
                <span id="activeStatusBadge" class="status-badge status-queued">Queued</span>
            </div>
        </div>
        <div class="card-body">
            <!-- Compression Progress -->
            <div id="compressionProgressSection" class="progress-section">
                <div class="progress-labels">
                    <span id="compressionStageText">Queued in background queue...</span>
                    <span id="compressionPercentText">0%</span>
                </div>
                <div class="progress-bar-bg">
                    <div id="compressionProgressBar" class="progress-bar-fill compression-fill" style="width: 0%"></div>
                </div>
                <div class="progress-stats-row">
                    <span id="processingTimeDisplay">Elapsed: 0s</span>
                    <span id="attemptCountDisplay">Attempt 1 of 3</span>
                </div>
            </div>

            <!-- Error message container -->
            <div id="compressionErrorAlert" class="alert alert-danger" style="display:none">
                <div class="alert-icon">⚠️</div>
                <div class="alert-content" id="compressionErrorMessage">
                    Compression error occurred.
                </div>
            </div>

            <!-- Output Preview & Results Section -->
            <div id="outputSuccessSection" style="display:none">
                <div class="results-summary-card">
                    <div class="results-grid">
                        <div class="result-metric">
                            <span class="metric-title">Original Size</span>
                            <span class="metric-val" id="resOriginalSize">0 MB</span>
                        </div>
                        <div class="result-metric">
                            <span class="metric-title">Output Size</span>
                            <span class="metric-val text-success" id="resOutputSize">0 MB</span>
                        </div>
                        <div class="result-metric">
                            <span class="metric-title">Space Saved</span>
                            <span class="metric-val text-accent" id="resSavedPercent">0%</span>
                        </div>
                        <div class="result-metric">
                            <span class="metric-title">Processing Time</span>
                            <span class="metric-val" id="resProcessingTime">0s</span>
                        </div>
                    </div>
                </div>

                <!-- Video Preview Player -->
                <div class="preview-player-wrapper">
                    <h3>Compressed Video Preview</h3>
                    <video id="previewPlayer" controls preload="metadata" class="video-preview-element">
                        Your browser does not support the video tag.
                    </video>
                </div>

                <!-- Action buttons -->
                <div class="action-row output-actions">
                    <a id="downloadBtn" href="#" class="btn btn-success btn-lg">
                        <span>⬇️ Download Compressed MP4</span>
                    </a>
                    <button type="button" class="btn btn-outline" id="compressAnotherBtn">
                        <span>🔄 Compress Another Video</span>
                    </button>
                </div>
            </div>

            <!-- Failure Action Row (Retry / Delete) -->
            <div id="failureActionsRow" class="action-row" style="display:none">
                <button type="button" class="btn btn-warning" id="retryJobBtn">
                    <span>🔄 Retry Compression</span>
                </button>
                <button type="button" class="btn btn-danger" id="deleteFailedJobBtn">
                    <span>🗑️ Delete Video</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Step 4: History Table Card -->
    <div class="card" id="historyCard">
        <div class="card-header">
            <div>
                <h2>Video Compression History</h2>
                <p class="card-desc">All videos stored in local private application storage.</p>
            </div>
            <button type="button" class="btn btn-sm btn-outline" id="refreshHistoryBtn">🔄 Refresh</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="history-table" id="historyTable">
                    <thead>
                        <tr>
                            <th>Video Name</th>
                            <th>Duration</th>
                            <th>Original Size</th>
                            <th>Target Size</th>
                            <th>Output Size</th>
                            <th>Saved</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        @forelse($videos as $video)
                            <tr data-uuid="{{ $video->uuid }}" id="row-{{ $video->uuid }}">
                                <td class="table-filename" title="{{ $video->original_filename }}">
                                    {{ Str::limit($video->original_filename, 28) }}
                                </td>
                                <td>{{ $video->formatted_duration }}</td>
                                <td>{{ $video->formatted_original_size }}</td>
                                <td>{{ $video->formatted_target_size ?? '-' }}</td>
                                <td>{{ $video->formatted_output_size ?? '-' }}</td>
                                <td>
                                    @if($video->percentage_saved !== null)
                                        <span class="badge badge-saved">{{ $video->percentage_saved }}%</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>
                                    <span class="status-badge status-{{ $video->status }}">
                                        {{ ucfirst($video->status) }}
                                    </span>
                                </td>
                                <td class="table-actions">
                                    @if($video->isCompleted())
                                        <button type="button" class="btn btn-xs btn-outline view-preview-btn" data-uuid="{{ $video->uuid }}">Preview</button>
                                        <a href="{{ route('videos.download', ['video' => $video->uuid]) }}" class="btn btn-xs btn-primary">Download</a>
                                    @elseif($video->isFailed())
                                        <button type="button" class="btn btn-xs btn-warning retry-table-btn" data-uuid="{{ $video->uuid }}">Retry</button>
                                    @endif
                                    @if(!$video->isProcessing())
                                        <button type="button" class="btn btn-xs btn-danger delete-table-btn" data-uuid="{{ $video->uuid }}">Delete</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr id="emptyHistoryRow">
                                <td colspan="8" class="text-center text-muted">No videos compressed yet. Upload a video above to begin!</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection

