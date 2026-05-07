@extends('layouts.app')

@section('content')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mustakshif - Halal Product Checker</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --primary: #0f766e;
            --primary-light: #14b8a6;
            --primary-dark: #0d5f57;
            --secondary: #0ea5e9;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --dark: #0f172a;
            --light: #f8fafc;
            --border: #e2e8f0;
            --text: #1e293b;
            --text-muted: #64748b;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.15);
            --shadow-xl: 0 20px 40px -10px rgba(0, 0, 0, 0.2);
        }

        html, body {
            height: 100%;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
        }

        .bot-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            background: var(--light);
        }

        /* ===== HEADER ===== */
        .bot-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 20px;
            box-shadow: var(--shadow-lg);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .header-content {
            max-width: 1000px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .header-brand i {
            font-size: 28px;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
        }

        .header-tagline {
            font-size: 13px;
            opacity: 0.9;
            display: none;
        }

        @media (max-width: 768px) {
            .header-tagline {
                display: block;
            }
        }

        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .header-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .header-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-2px);
        }

        /* ===== LOCATION BAR ===== */
        .location-bar {
            background: linear-gradient(135deg, #ecfdf5 0%, #e0f9f7 100%);
            border-bottom: 1px solid #a7f3d0;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .location-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--primary-dark);
            font-weight: 500;
        }

        .location-status i {
            color: var(--primary);
            font-size: 16px;
        }

        .location-actions {
            display: flex;
            gap: 8px;
        }

        .location-btn, .clear-location-btn {
            border: 0;
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .location-btn {
            background: var(--primary);
            color: white;
        }

        .location-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .clear-location-btn {
            background: #fee2e2;
            color: #991b1b;
        }

        .clear-location-btn:hover {
            background: #fecaca;
        }

        .location-btn:disabled, .clear-location-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* ===== CHAT BOX ===== */
        .chat-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            max-width: 1000px;
            margin: 0 auto;
            width: 100%;
            scroll-behavior: smooth;
        }

        .chat-messages::-webkit-scrollbar {
            width: 8px;
        }

        .chat-messages::-webkit-scrollbar-track {
            background: transparent;
        }

        .chat-messages::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .message-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message-group.user {
            align-items: flex-end;
        }

        .message-group.bot {
            align-items: flex-start;
        }

        .message-bubble {
            padding: 14px 18px;
            border-radius: 16px;
            max-width: 70%;
            word-break: break-word;
            line-height: 1.6;
            box-shadow: var(--shadow-sm);
            animation: popIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes popIn {
            0% {
                opacity: 0;
                transform: scale(0.8);
            }
            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        .message-bubble.user {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message-bubble.bot {
            background: white;
            color: var(--text);
            border: 1px solid var(--border);
            border-bottom-left-radius: 4px;
        }

        .message-bubble.system {
            background: linear-gradient(135deg, #fef3c7 0%, #fcd34d 100%);
            color: #92400e;
            border: 1px solid #fde68a;
            max-width: 80%;
            margin: 0 auto;
            text-align: center;
        }

        .message-avatar {
            font-size: 18px;
            margin-bottom: 4px;
        }

        .message-time {
            font-size: 11px;
            opacity: 0.6;
            margin-top: 4px;
        }

        /* ===== TYPING INDICATOR ===== */
        .typing-indicator {
            display: flex;
            gap: 6px;
            align-items: center;
            padding: 14px 18px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
            border-bottom-left-radius: 4px;
            width: fit-content;
        }

        .typing-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--text-muted);
            animation: typingBounce 1.4s infinite;
        }

        .typing-dot:nth-child(2) {
            animation-delay: 0.2s;
        }

        .typing-dot:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes typingBounce {
            0%, 60%, 100% {
                opacity: 0.3;
                transform: translateY(0);
            }
            30% {
                opacity: 1;
                transform: translateY(-10px);
            }
        }

        /* ===== CONTEXT INFO ===== */
        .context-info {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px;
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 8px;
        }

        .context-info strong {
            color: var(--text);
        }

        .context-line {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 6px 0;
        }

        .context-line i {
            color: var(--primary);
            width: 14px;
        }

        /* ===== STATUS CHIPS ===== */
        .status-chips {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            animation: slideIn 0.3s ease;
        }

        .chip-success {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            color: #166534;
            border: 1px solid #86efac;
        }

        .chip-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fcd34d 100%);
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .chip-error {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .chip-neutral {
            background: linear-gradient(135deg, #eef2ff 0%, #ddd6fe 100%);
            color: #3730a3;
            border: 1px solid #c7d2fe;
        }

        /* ===== PRODUCT CARDS ===== */
        .product-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 16px;
            width: 100%;
        }

        .product-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-sm);
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .product-image {
            width: 100%;
            height: 140px;
            object-fit: cover;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        }

        .product-body {
            padding: 12px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .product-name {
            font-weight: 700;
            font-size: 13px;
            line-height: 1.4;
            min-height: 32px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .product-meta {
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
            margin: 2px 0;
        }

        .product-meta i {
            color: var(--primary);
            width: 12px;
        }

        .product-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: auto;
        }

        .badge-halal {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            color: #166534;
        }

        .badge-haram {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }

        .badge-mushbooh {
            background: linear-gradient(135deg, #fef3c7 0%, #fcd34d 100%);
            color: #92400e;
        }

        .badge-unknown {
            background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
            color: #374151;
        }

        /* ===== INPUT AREA ===== */
        .input-section {
            border-top: 1px solid var(--border);
            background: white;
            padding: 16px 20px;
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.05);
        }

        .input-wrapper {
            max-width: 1000px;
            margin: 0 auto;
        }

        .input-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .input-field {
            flex: 1;
            min-width: 200px;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            font-size: 14px;
            background: var(--light);
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .input-field:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1);
        }

        .input-field::placeholder {
            color: var(--text-muted);
        }

        .input-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn {
            border: 0;
            padding: 12px 18px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            box-shadow: var(--shadow-md);
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-secondary {
            background: var(--light);
            color: var(--primary);
            border: 1.5px solid var(--primary);
        }

        .btn-secondary:hover:not(:disabled) {
            background: var(--primary);
            color: white;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* ===== IMAGE PREVIEW ===== */
        .image-preview-section {
            margin-bottom: 12px;
        }

        .image-preview {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-radius: 10px;
            border: 1px solid #bae6fd;
        }

        .image-preview img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #0284c7;
        }

        .image-preview-info {
            flex: 1;
            font-size: 12px;
            color: #0c4a6e;
        }

        .image-preview-name {
            font-weight: 600;
            margin-bottom: 2px;
        }

        .hidden {
            display: none !important;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .message-bubble {
                max-width: 85%;
            }

            .product-cards {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }

            .input-row {
                flex-direction: column;
                align-items: stretch;
            }

            .input-actions {
                width: 100%;
            }

            .btn {
                width: 100%;
            }

            .header-content {
                flex-direction: column;
                text-align: center;
            }

            .location-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .location-actions {
                justify-content: stretch;
            }

            .location-btn, .clear-location-btn {
                flex: 1;
                justify-content: center;
            }

            .chat-messages {
                padding: 20px 12px;
            }

            .input-section {
                padding: 12px 16px;
            }
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            text-align: center;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: var(--primary);
            opacity: 0.6;
        }

        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: var(--text);
        }

        .empty-state p {
            font-size: 14px;
            max-width: 400px;
        }
    </style>
</head>
<body>
<div class="bot-container">
    <!-- HEADER -->
    <div class="bot-header">
        <div class="header-content">
            <div class="header-brand">
                <i class="fas fa-leaf"></i>
                <div>
                    <div>Mustakshif</div>
                    <div class="header-tagline">Halal Product Checker</div>
                </div>
            </div>
            <div class="header-actions">
                <button class="header-btn" id="infoBtn" title="Help">
                    <i class="fas fa-question-circle"></i> Help
                </button>
            </div>
        </div>
    </div>

    <!-- LOCATION BAR -->
    <div class="location-bar">
        <div class="location-status">
            <i class="fas fa-map-marker-alt"></i>
            <span id="locationStatusText">Location: <strong>Not enabled</strong></span>
        </div>
        <div class="location-actions">
            <button class="location-btn" id="enableLocationBtn" title="Enable location detection">
                <i class="fas fa-location-crosshairs"></i> Enable Location
            </button>
            <button class="clear-location-btn hidden" id="clearLocationBtn" title="Clear location preference">
                <i class="fas fa-times"></i> Clear
            </button>
        </div>
    </div>

    <!-- CHAT CONTAINER -->
    <div class="chat-container">
        <div class="chat-messages" id="chatMessages">
            <div class="message-group bot">
                <div class="message-bubble bot">
                    <strong>Assalam o Alaikum! 👋</strong><br><br>
                    I'm Mustakshif, your Halal Product Checker. I can help you verify products by:
                    <br><br>
                    ✓ Product name or brand<br>
                    ✓ Barcode scanning<br>
                    ✓ Product photos<br>
                    ✓ Categories & ingredients<br>
                    ✓ Country of origin
                </div>
            </div>
        </div>
    </div>

    <!-- INPUT SECTION -->
    <div class="input-section">
        <form class="input-wrapper" id="chatForm" enctype="multipart/form-data">
            @csrf

            <div id="imagePreviewSection" class="image-preview-section hidden">
                <div class="image-preview">
                    <img id="previewImg" src="" alt="Selected image">
                    <div class="image-preview-info">
                        <div class="image-preview-name" id="previewName"></div>
                        <div id="previewSize"></div>
                    </div>
                    <button type="button" class="btn btn-secondary" id="removeImageBtn" style="min-width: auto; padding: 8px 12px;">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>

            <div class="input-row">
                <input
                    type="text"
                    id="messageInput"
                    class="input-field"
                    name="message"
                    placeholder="Ask me anything... product name, barcode, ingredient, category, origin..."
                    autocomplete="off"
                >

                <label class="btn btn-secondary" id="uploadLabel" for="imageInput" title="Upload product photo">
                    <i class="fas fa-image"></i>
                    <span>Photo</span>
                </label>

                <button type="submit" class="btn btn-primary" id="sendBtn">
                    <i class="fas fa-paper-plane"></i>
                    <span>Send</span>
                </button>

                <input type="file" id="imageInput" name="image" accept="image/*" capture="environment" class="hidden">
            </div>
        </form>
    </div>
</div>

<script>
    const form = document.getElementById('chatForm');
    const messageInput = document.getElementById('messageInput');
    const imageInput = document.getElementById('imageInput');
    const chatMessages = document.getElementById('chatMessages');
    const sendBtn = document.getElementById('sendBtn');
    const enableLocationBtn = document.getElementById('enableLocationBtn');
    const clearLocationBtn = document.getElementById('clearLocationBtn');
    const locationStatusText = document.getElementById('locationStatusText');
    const uploadLabel = document.getElementById('uploadLabel');
    const imagePreviewSection = document.getElementById('imagePreviewSection');
    const previewImg = document.getElementById('previewImg');
    const previewName = document.getElementById('previewName');
    const previewSize = document.getElementById('previewSize');
    const removeImageBtn = document.getElementById('removeImageBtn');
    const infoBtn = document.getElementById('infoBtn');

    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    let isSending = false;
    let selectedImageUrl = null;

    function formatFileSize(bytes) {
        if (!bytes || isNaN(bytes)) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function setLocationStatus(country = null) {
        if (country) {
            locationStatusText.innerHTML = `Location: <strong>${country}</strong> <i class="fas fa-check" style="color: var(--success);"></i>`;
            clearLocationBtn.classList.remove('hidden');
        } else {
            locationStatusText.innerHTML = `Location: <strong>Not enabled</strong>`;
            clearLocationBtn.classList.add('hidden');
        }
    }

    function updateImagePreview(file) {
        if (!file) {
            imagePreviewSection.classList.add('hidden');
            selectedImageUrl = null;
            return;
        }

        selectedImageUrl = URL.createObjectURL(file);
        previewImg.src = selectedImageUrl;
        previewName.textContent = file.name;
        previewSize.textContent = formatFileSize(file.size);
        imagePreviewSection.classList.remove('hidden');
    }

    function addMessage(role, content, data = null, userImageUrl = null) {
        const messageGroup = document.createElement('div');
        messageGroup.className = `message-group ${role}`;

        if (role === 'user' && userImageUrl) {
            const imageBubble = document.createElement('div');
            imageBubble.style.cssText = 'width: 180px; border-radius: 12px; overflow: hidden; margin-bottom: 8px;';
            const img = document.createElement('img');
            img.src = userImageUrl;
            img.alt = 'Uploaded image';
            img.style.cssText = 'width: 100%; height: 180px; object-fit: cover; display: block;';
            imageBubble.appendChild(img);
            messageGroup.appendChild(imageBubble);
        }

        const bubble = document.createElement('div');
        bubble.className = `message-bubble ${role}`;
        bubble.textContent = content;
        messageGroup.appendChild(bubble);

        if (role === 'bot' && data) {
            if (data.meta?.result_count > 0) {
                const statusChips = document.createElement('div');
                statusChips.className = 'status-chips';

                const chip = document.createElement('span');
                chip.className = 'status-chip chip-success';
                chip.innerHTML = `<i class="fas fa-check-circle"></i> Found ${data.meta.result_count} result${data.meta.result_count > 1 ? 's' : ''}`;
                statusChips.appendChild(chip);

                if (data.meta?.search_summary) {
                    const summaryChip = document.createElement('span');
                    summaryChip.className = 'status-chip chip-neutral';
                    summaryChip.innerHTML = `<i class="fas fa-info-circle"></i> ${data.meta.search_summary}`;
                    statusChips.appendChild(summaryChip);
                }

                messageGroup.appendChild(statusChips);
            }

            if (data.meta?.image_context) {
                const context = data.meta.image_context;
                if (context.barcode || context.product_name || context.brand) {
                    const contextBox = document.createElement('div');
                    contextBox.className = 'context-info';
                    contextBox.innerHTML = `
                        <strong><i class="fas fa-image"></i> Image Detection</strong><br>
                        ${context.barcode ? `<div class="context-line"><i class="fas fa-barcode"></i> ${context.barcode}</div>` : ''}
                        ${context.product_name ? `<div class="context-line"><i class="fas fa-tag"></i> ${context.product_name}</div>` : ''}
                        ${context.brand ? `<div class="context-line"><i class="fas fa-store"></i> ${context.brand}</div>` : ''}
                    `;
                    messageGroup.appendChild(contextBox);
                }
            }

            if (data.products && data.products.length > 0) {
                const cardsContainer = document.createElement('div');
                cardsContainer.className = 'product-cards';

                data.products.slice(0, 6).forEach(product => {
                    const card = document.createElement('a');
                    card.href = product.product_url || product.listing_url || 'https://www.mustakshif.com/list-of-products';
                    card.target = '_blank';
                    card.rel = 'noopener noreferrer';
                    card.className = 'product-card';

                    const decision = normalizeDecision(product.type || product.decision || product.status || 'unknown');

                    card.innerHTML = `
                        <img src="${product.image_url || ''}" alt="${product.name || 'Product'}" class="product-image" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 200 140%22><rect fill=%22%23f0f9ff%22 width=%22200%22 height=%22140%22/><text x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 font-size=%2214%22 fill=%22%230ea5e9%22>No Image</text></svg>'">
                        <div class="product-body">
                            <div class="product-name">${product.name || 'Product'}</div>
                            ${product.brand ? `<div class="product-meta"><i class="fas fa-store"></i> ${product.brand}</div>` : ''}
                            ${product.barcode ? `<div class="product-meta"><i class="fas fa-barcode"></i> ${product.barcode}</div>` : ''}
                            ${product.origin ? `<div class="product-meta"><i class="fas fa-globe"></i> ${product.origin}</div>` : ''}
                            <span class="product-badge badge-${decision}">${decision.toUpperCase()}</span>
                        </div>
                    `;

                    cardsContainer.appendChild(card);
                });

                messageGroup.appendChild(cardsContainer);
            }
        }

        chatMessages.appendChild(messageGroup);
        scrollToBottom();
    }

    function normalizeDecision(value) {
        const normalized = String(value || '').trim().toLowerCase();
        if (['halal', 'allowed', 'permissible', 'safe'].includes(normalized)) return 'halal';
        if (['haram', 'forbidden', 'not_halal', 'not halal', 'unsafe'].includes(normalized)) return 'haram';
        if (['mashbooh', 'mushbooh', 'doubtful', 'dubious', 'uncertain', 'suspicious', 'review'].includes(normalized)) return 'mushbooh';
        return 'unknown';
    }

    function showTyping() {
        const messageGroup = document.createElement('div');
        messageGroup.className = 'message-group bot';
        messageGroup.id = 'typingGroup';

        const typing = document.createElement('div');
        typing.className = 'typing-indicator';
        typing.innerHTML = `
            <span>Checking product</span>
            <div class="typing-dot"></div>
            <div class="typing-dot"></div>
            <div class="typing-dot"></div>
        `;

        messageGroup.appendChild(typing);
        chatMessages.appendChild(messageGroup);
        scrollToBottom();
    }

    function removeTyping() {
        const el = document.getElementById('typingGroup');
        if (el) el.remove();
    }

    function setSendingState(sending) {
        isSending = sending;
        sendBtn.disabled = sending;
        messageInput.disabled = sending;
        imageInput.disabled = sending;
        removeImageBtn.disabled = sending;
        uploadLabel.classList.toggle('disabled', sending);

        if (sending) {
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        } else {
            sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> <span>Send</span>';
        }
    }

    function resetForm() {
        messageInput.value = '';
        imageInput.value = '';
        updateImagePreview(null);
        setSendingState(false);
        messageInput.focus();
    }

    imageInput.addEventListener('change', (e) => {
        const file = e.target.files?.[0];
        updateImagePreview(file);
    });

    removeImageBtn.addEventListener('click', (e) => {
        e.preventDefault();
        imageInput.value = '';
        updateImagePreview(null);
    });

    messageInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !isSending) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const message = messageInput.value.trim();
        const image = imageInput.files?.[0];

        if (!message && !image) {
            addMessage('bot', '⚠️ Please type a message or upload a product image.');
            return;
        }

        const userImageUrl = image ? URL.createObjectURL(image) : null;
        addMessage('user', message || '[📸 Image uploaded]', null, userImageUrl);

        setSendingState(true);
        showTyping();

        try {
            const formData = new FormData();
            if (message) formData.append('message', message);
            if (image) formData.append('image', image);

            const response = await fetch('{{ route("chat.send") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: formData
            });

            const result = await response.json();
            removeTyping();

            if (!response.ok) {
                addMessage('bot', result?.reply || '❌ Something went wrong while checking the product database.');
            } else {
                addMessage('bot', result?.reply || '✓ Product information is not available yet.', result?.data || null);
            }

            resetForm();
            if (userImageUrl) URL.revokeObjectURL(userImageUrl);
        } catch (error) {
            removeTyping();
            addMessage('bot', '❌ Connection error. Please try again.');
            resetForm();
            if (userImageUrl) URL.revokeObjectURL(userImageUrl);
        }
    });

    enableLocationBtn.addEventListener('click', async () => {
        if (!navigator.geolocation) {
            addMessage('bot', '⚠️ Your browser does not support geolocation.');
            return;
        }

        enableLocationBtn.disabled = true;
        enableLocationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Detecting...';

        navigator.geolocation.getCurrentPosition(
            async (position) => {
                try {
                    const { latitude, longitude } = position.coords;
                    const response = await fetch(
                        `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${latitude}&lon=${longitude}&zoom=3&addressdetails=1`
                    );

                    const geoData = await response.json();
                    const country = geoData?.address?.country;

                    if (!country) {
                        addMessage('bot', '⚠️ Could not detect your country from location.');
                        return;
                    }

                    const saveResponse = await fetch('{{ route("chat.location.save") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            country: country,
                            country_code: geoData?.address?.country_code?.toUpperCase() || '',
                            latitude: latitude,
                            longitude: longitude
                        })
                    });

                    if (saveResponse.ok) {
                        setLocationStatus(country);
                        addMessage('bot', `✅ Location enabled! I'll now prioritize products from ${country}.`);
                    }
                } catch (error) {
                    addMessage('bot', '❌ Failed to save location preference.');
                } finally {
                    enableLocationBtn.disabled = false;
                    enableLocationBtn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Enable Location';
                }
            },
            () => {
                addMessage('bot', '❌ Location permission was denied.');
                enableLocationBtn.disabled = false;
                enableLocationBtn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Enable Location';
            }
        );
    });

    clearLocationBtn.addEventListener('click', async () => {
        clearLocationBtn.disabled = true;
        clearLocationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Clearing...';

        try {
            const response = await fetch('{{ route("chat.location.clear") }}', {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                }
            });

            if (response.ok) {
                setLocationStatus(null);
                addMessage('bot', '✅ Location preference cleared.');
            }
        } catch (error) {
            addMessage('bot', '❌ Failed to clear location preference.');
        } finally {
            clearLocationBtn.disabled = false;
            clearLocationBtn.innerHTML = '<i class="fas fa-times"></i> Clear';
        }
    });

    infoBtn.addEventListener('click', () => {
        addMessage('bot', `
📋 How to use Mustakshif:

1️⃣ Ask by product name: "Is Dairy Milk halal?"
2️⃣ Scan barcode: "Check 812345678901"
3️⃣ Upload photo: Click "Photo" and capture your product
4️⃣ Filter by category: "Show me halal chocolates from USA"
5️⃣ Check ingredients: "Products without gelatin?"
6️⃣ Get recommendations: "Suggest me halal candies"

Status badges:
🟢 Halal - Safe to consume
🔴 Haram - Not permissible
🟡 Mushbooh - Doubtful status
⚫ Unknown - Need more data
        `);
    });

    scrollToBottom();
</script>
</body>
</html>
@endsection