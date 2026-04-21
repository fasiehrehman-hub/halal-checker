<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mustakshif Halal Product Checker</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        * { box-sizing: border-box; }

        :root {
            --bg: #f4f7fb;
            --panel: #ffffff;
            --panel-soft: #f9fafb;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --brand: #0f766e;
            --brand-2: #0ea5e9;
            --danger: #dc2626;
            --warning-bg: #fef3c7;
            --warning-text: #92400e;
            --success-bg: #dcfce7;
            --success-text: #166534;
            --error-bg: #fee2e2;
            --error-text: #991b1b;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .wrapper {
            max-width: 1120px;
            margin: 28px auto;
            padding: 0 16px;
        }

        .chat-shell {
            background: var(--panel);
            border-radius: 20px;
            box-shadow: 0 14px 40px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .chat-header {
            padding: 22px 20px;
            background: linear-gradient(135deg, var(--brand), var(--brand-2));
            color: #fff;
        }

        .chat-header h1 {
            margin: 0 0 6px;
            font-size: 24px;
        }

        .chat-header p {
            margin: 0;
            opacity: .96;
            line-height: 1.5;
        }

        .location-bar {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            padding: 14px 16px;
            background: #ecfeff;
            border-bottom: 1px solid #dbeafe;
        }

        .location-status {
            font-size: 14px;
            color: #0f172a;
        }

        .location-status strong {
            color: var(--brand);
        }

        .location-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .location-btn,
        .clear-location-btn {
            border: 0;
            color: #fff;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            min-height: 42px;
        }

        .location-btn {
            background: var(--brand);
        }

        .clear-location-btn {
            background: var(--danger);
        }

        .location-btn:disabled,
        .clear-location-btn:disabled,
        .chat-form button:disabled,
        .upload-btn.disabled,
        .remove-btn:disabled {
            opacity: .65;
            cursor: not-allowed;
        }

        .chat-box {
            height: 65vh;
            overflow-y: auto;
            padding: 20px;
            background: var(--panel-soft);
            scroll-behavior: smooth;
        }

        .message {
            margin-bottom: 18px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            width: 100%;
        }

        .message.user {
            align-items: flex-end;
        }

        .message.bot,
        .message.system {
            align-items: flex-start;
        }

        .bubble {
            max-width: 78%;
            padding: 14px 16px;
            border-radius: 16px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .user .bubble {
            background: var(--brand-2);
            color: #fff;
            border-bottom-right-radius: 6px;
        }

        .bot .bubble {
            background: #fff;
            color: var(--text);
            border: 1px solid var(--border);
            border-bottom-left-radius: 6px;
        }

        .system .bubble,
        .system-note {
            background: #fefce8;
            color: #854d0e;
            border: 1px solid #fde68a;
        }

        .message-meta {
            font-size: 12px;
            color: var(--muted);
            padding: 0 4px;
        }

        .user-preview {
            max-width: 220px;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid #dbe3ea;
            background: #fff;
        }

        .user-preview img {
            width: 100%;
            display: block;
            object-fit: cover;
            max-height: 220px;
        }

        .bot-extra {
            width: min(100%, 880px);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .result-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .chip-neutral {
            background: #eef2ff;
            color: #3730a3;
            border-color: #c7d2fe;
        }

        .chip-success {
            background: var(--success-bg);
            color: var(--success-text);
            border-color: #86efac;
        }

        .chip-warning {
            background: var(--warning-bg);
            color: var(--warning-text);
            border-color: #fcd34d;
        }

        .chip-error {
            background: var(--error-bg);
            color: var(--error-text);
            border-color: #fca5a5;
        }

        .context-box {
            width: min(100%, 880px);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px 14px;
            font-size: 13px;
            color: #374151;
        }

        .context-box strong {
            color: #111827;
        }

        .debug-box {
            width: min(100%, 880px);
            background: #0f172a;
            color: #e2e8f0;
            border: 1px solid #1e293b;
            border-radius: 14px;
            padding: 12px 14px;
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.5;
        }

        .debug-box summary {
            cursor: pointer;
            font-weight: 700;
            color: #93c5fd;
            margin-bottom: 8px;
        }

        .debug-box pre {
            margin: 8px 0 0;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 12px;
            width: min(100%, 880px);
        }

        .card {
            display: block;
            text-decoration: none;
            color: inherit;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(0,0,0,0.06);
            transition: 0.2s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.10);
        }

        .card img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            display: block;
            background: #f3f4f6;
        }

        .card-body {
            padding: 12px;
        }

        .card-title {
            margin: 0 0 8px;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.4;
            min-height: 42px;
        }

        .meta {
            font-size: 13px;
            color: #4b5563;
            margin-bottom: 5px;
            line-height: 1.4;
            word-break: break-word;
        }

        .badge {
            display: inline-block;
            margin-top: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-halal {
            background: #dcfce7;
            color: #166534;
        }

        .badge-haram {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-mashbooh {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-unknown {
            background: #e5e7eb;
            color: #374151;
        }

        .chat-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 16px;
            border-top: 1px solid var(--border);
            background: #fff;
        }

        .form-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .chat-form input[type="text"] {
            flex: 1;
            min-width: 240px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 14px;
            font-size: 15px;
            outline: none;
            transition: .15s ease;
        }

        .chat-form input[type="text"]:focus {
            border-color: var(--brand-2);
            box-shadow: 0 0 0 3px rgba(14,165,233,0.12);
        }

        .chat-form button,
        .upload-btn,
        .remove-btn {
            border: 0;
            color: #fff;
            padding: 0 18px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .chat-form button {
            background: var(--brand);
            min-width: 108px;
        }

        .upload-btn {
            background: #334155;
        }

        .remove-btn {
            background: var(--danger);
        }

        .typing {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--muted);
            font-size: 13px;
        }

        .typing-dots {
            display: inline-flex;
            gap: 4px;
        }

        .typing-dots span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #94a3b8;
            animation: blink 1.2s infinite ease-in-out;
        }

        .typing-dots span:nth-child(2) { animation-delay: 0.15s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.3s; }

        @keyframes blink {
            0%, 80%, 100% { opacity: 0.25; transform: translateY(0); }
            40% { opacity: 1; transform: translateY(-2px); }
        }

        .hidden {
            display: none;
        }

        .helper-text {
            font-size: 13px;
            color: #475569;
        }

        .selected-preview {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .selected-preview img {
            width: 90px;
            height: 90px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid #d1d5db;
            background: #fff;
        }

        .selected-preview-info {
            font-size: 13px;
            color: #475569;
        }

        .empty-image {
            width: 100%;
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            color: #94a3b8;
            font-size: 13px;
        }

        .footer-note {
            font-size: 12px;
            color: var(--muted);
        }

        @media (max-width: 768px) {
            .chat-box {
                height: 60vh;
            }

            .bubble {
                max-width: 92%;
            }

            .form-row {
                flex-direction: column;
                align-items: stretch;
            }

            .chat-form button,
            .upload-btn,
            .remove-btn {
                width: 100%;
            }

            .chat-form input[type="text"] {
                width: 100%;
            }

            .location-actions {
                width: 100%;
            }

            .location-btn,
            .clear-location-btn {
                width: 100%;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .context-box,
            .bot-extra {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="chat-shell">
        <div class="chat-header">
            <h1>Mustakshif Product Checker</h1>
            <p>Ask by product name, barcode, brand, category, ingredient, or upload a product photo.</p>
        </div>

        <div class="location-bar">
            <div>
                <div class="location-status" id="locationStatus">
                    Location preference:
                    <strong>{{ session('chat_location.country') ? session('chat_location.country') : 'Not enabled' }}</strong>
                </div>
            </div>

            <div class="location-actions">
                <button type="button" class="location-btn" id="enableLocationBtn">Enable Location</button>
                <button type="button" class="clear-location-btn" id="clearLocationBtn">Clear Location</button>
            </div>
        </div>

        <div class="chat-box" id="chatBox">
            <div class="message bot">
                <div class="bubble">Assalam o Alaikum! Here you can check halal, haram and mashbooh products by yourself.</div>
                <div class="message-meta">Assistant</div>
            </div>
        </div>

        <form class="chat-form" id="chatForm" enctype="multipart/form-data">
            <div class="form-row">
                <input
                    type="text"
                    id="messageInput"
                    name="message"
                    placeholder="Example: Check this product / show halal drinks / gelatin products"
                    autocomplete="off"
                >

                <label class="upload-btn" for="imageInput" id="uploadLabel">Upload Photo</label>
                <input
                    type="file"
                    id="imageInput"
                    name="image"
                    accept="image/*"
                    capture="environment"
                    class="hidden"
                >

                <button type="submit" id="sendBtn">Send</button>
            </div>

            <div class="helper-text" id="selectedFileText">No photo selected</div>

            <div class="selected-preview hidden" id="selectedPreviewWrap">
                <img id="selectedPreviewImage" src="" alt="Selected image preview">
                <div class="selected-preview-info">
                    <div id="selectedPreviewName"></div>
                    <div id="selectedPreviewSize"></div>
                </div>
                <button type="button" class="remove-btn" id="removeImageBtn">Remove</button>
            </div>

            <div class="footer-note">
                Tip: You can send product name, barcode, ingredient, category, or an image.
            </div>
        </form>
    </div>
</div>

<script>
    const form = document.getElementById('chatForm');
    const input = document.getElementById('messageInput');
    const imageInput = document.getElementById('imageInput');
    const uploadLabel = document.getElementById('uploadLabel');
    const selectedFileText = document.getElementById('selectedFileText');
    const chatBox = document.getElementById('chatBox');
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const sendBtn = document.getElementById('sendBtn');

    const selectedPreviewWrap = document.getElementById('selectedPreviewWrap');
    const selectedPreviewImage = document.getElementById('selectedPreviewImage');
    const selectedPreviewName = document.getElementById('selectedPreviewName');
    const selectedPreviewSize = document.getElementById('selectedPreviewSize');
    const removeImageBtn = document.getElementById('removeImageBtn');

    const enableLocationBtn = document.getElementById('enableLocationBtn');
    const clearLocationBtn = document.getElementById('clearLocationBtn');
    const locationStatus = document.getElementById('locationStatus');

    let isSending = false;
    let selectedPreviewObjectUrl = null;

    function scrollToBottom() {
        chatBox.scrollTop = chatBox.scrollHeight;
    }

    function formatFileSize(bytes) {
        if (!bytes || isNaN(bytes)) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function escapeText(value) {
        return String(value ?? '');
    }

    function normalizeDecisionValue(value) {
        const normalized = String(value ?? '').trim().toLowerCase();

        if (['halal', 'allowed', 'permissible', 'safe'].includes(normalized)) {
            return 'halal';
        }

        if (['haram', 'forbidden', 'not_halal', 'not halal', 'unsafe'].includes(normalized)) {
            return 'haram';
        }

        if ([
            'mashbooh',
            'mushbooh',
            'doubtful',
            'dubious',
            'questionable',
            'uncertain',
            'suspicious',
            'review'
        ].includes(normalized)) {
            return 'mushbooh';
        }

        if ([
            '',
            'unknown',
            'n/a',
            'na',
            'null',
            'undefined',
            'not_sure',
            'not sure'
        ].includes(normalized)) {
            return 'unknown';
        }

        return 'unknown';
    }

    function firstNonEmptyDecisionValue(candidates) {
        for (const value of candidates) {
            if (value === null || value === undefined) continue;

            if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
                const text = String(value).trim();
                if (text !== '') return text;
            }

            if (typeof value === 'object') {
                const nested = value.status ?? value.type ?? value.decision ?? value.verdict ?? value.label ?? value.value ?? '';
                const text = String(nested ?? '').trim();
                if (text !== '') return text;
            }
        }

        return '';
    }

    function extractProductDecision(product) {
        if (!product || typeof product !== 'object') {
            return 'unknown';
        }

        const rawDecision = firstNonEmptyDecisionValue([
            product.status,
            product.type,
            product.decision,
            product.verdict,
            product.judgement,
            product.judgment,
            product.result,
            product.halal_status,
            product.product_status,
            product.product_decision,
            product.verdict_status,
            product?.meta?.status,
            product?.meta?.type,
            product?.meta?.decision,
            product?.attributes?.status,
            product?.attributes?.type,
            product?.attributes?.decision,
            product?.pivot?.status,
            product?.pivot?.type,
            product?.pivot?.decision
        ]);

        return normalizeDecisionValue(rawDecision);
    }

    function getDecisionClass(decision) {
        const value = normalizeDecisionValue(decision);
        if (value === 'halal') return 'badge-halal';
        if (value === 'haram') return 'badge-haram';
        if (value === 'mushbooh') return 'badge-mashbooh';
        return 'badge-unknown';
    }

    function getDecisionText(decision) {
        const value = normalizeDecisionValue(decision);
        if (value === 'halal') return 'Halal';
        if (value === 'haram') return 'Haram';
        if (value === 'mushbooh') return 'Mushbooh';
        return 'Unknown';
    }

    function normalizeLookupStatus(status, count = 0) {
        const normalized = String(status ?? '').trim().toLowerCase();

        if (!normalized) {
            return count > 0 ? 'found' : 'not_found';
        }

        if ([
            'found',
            'success',
            'ok',
            'matched',
            'resolved',
            'exact_match',
            'exact',
            'best_match',
            'single_match',
            'has_results'
        ].includes(normalized)) {
            return 'found';
        }

        if ([
            'partial_found',
            'partial_match',
            'multiple_found',
            'multiple_matches',
            'fallback_match',
            'suggestions',
            'search_results',
            'close_match',
            'approximate_match'
        ].includes(normalized)) {
            return count > 0 ? 'found' : 'not_found';
        }

        if ([
            'not_found',
            'no_match',
            'no_exact_match',
            'not matched',
            'not-matched',
            'empty',
            'unavailable',
            'unknown'
        ].includes(normalized)) {
            return 'not_found';
        }

        if ([
            'error',
            'failed',
            'exception',
            'validation_error',
            'server_error'
        ].includes(normalized)) {
            return 'error';
        }

        return count > 0 ? 'found' : 'not_found';
    }

    function getStatusChip(status, count = 0) {
        const wrap = document.createElement('div');
        wrap.className = 'result-summary';

        const chip1 = document.createElement('span');
        const normalized = normalizeLookupStatus(status, count);

        if (normalized === 'found') {
            chip1.className = 'chip chip-success';
            chip1.textContent = count > 0 ? `Found ${count} result${count > 1 ? 's' : ''}` : 'Result found';
        } else if (normalized === 'error') {
            chip1.className = 'chip chip-error';
            chip1.textContent = 'Error';
        } else {
            chip1.className = 'chip chip-warning';
            chip1.textContent = count > 0 ? `Found ${count} possible result${count > 1 ? 's' : ''}` : 'No exact match';
        }

        wrap.appendChild(chip1);
        return wrap;
    }

    function setLocationStatus(country = null) {
        if (country) {
            locationStatus.innerHTML = `Location preference: <strong>${country}</strong>`;
        } else {
            locationStatus.innerHTML = `Location preference: <strong>Not enabled</strong>`;
        }
    }

    function clearSelectedPreviewObjectUrl() {
        if (selectedPreviewObjectUrl) {
            URL.revokeObjectURL(selectedPreviewObjectUrl);
            selectedPreviewObjectUrl = null;
        }
    }

    function setSelectedImagePreview(file) {
        clearSelectedPreviewObjectUrl();

        if (!file) {
            selectedPreviewWrap.classList.add('hidden');
            selectedPreviewImage.src = '';
            selectedPreviewName.textContent = '';
            selectedPreviewSize.textContent = '';
            selectedFileText.textContent = 'No photo selected';
            return;
        }

        selectedFileText.textContent = file.name;
        selectedPreviewWrap.classList.remove('hidden');

        selectedPreviewObjectUrl = URL.createObjectURL(file);
        selectedPreviewImage.src = selectedPreviewObjectUrl;
        selectedPreviewName.textContent = file.name;
        selectedPreviewSize.textContent = formatFileSize(file.size);
    }

    function createEmptyImageNode() {
        const div = document.createElement('div');
        div.className = 'empty-image';
        div.textContent = 'No Image';
        return div;
    }

    function appendMetaText(parent, label, value) {
        if (!value) return;
        const div = document.createElement('div');
        div.className = 'meta';
        div.textContent = `${label}: ${value}`;
        parent.appendChild(div);
        
    }

    function appendContextInfo(data) {
        if (!data || typeof data !== 'object') return null;

        const meta = data.meta || {};
        const imageContext = meta.image_context || null;
        const searchSummary = meta.search_summary || '';
        const resultCount = Number(meta.result_count || 0);

        const holder = document.createElement('div');
        holder.className = 'bot-extra';

        holder.appendChild(getStatusChip(data.status || '', resultCount));

        if (searchSummary) {
            const chipRow = document.createElement('div');
            chipRow.className = 'result-summary';

            const chip = document.createElement('span');
            chip.className = 'chip chip-neutral';
            chip.textContent = searchSummary;

            chipRow.appendChild(chip);
            holder.appendChild(chipRow);
        }

        if (imageContext && (imageContext.barcode || imageContext.product_name || imageContext.brand)) {
            const context = document.createElement('div');
            context.className = 'context-box';

            const lines = [];
            if (imageContext.barcode) lines.push(`Barcode detected: ${imageContext.barcode}`);
            if (imageContext.product_name) lines.push(`Product name detected: ${imageContext.product_name}`);
            if (imageContext.brand) lines.push(`Brand detected: ${imageContext.brand}`);

            context.innerHTML = `<strong>Image detection:</strong> ${lines.join(' | ')}`;
            holder.appendChild(context);
        }

        return holder;
    }

    function appendDebugInfo(data) {
        const debugPayload = data?.debug || data?.meta?.debug || data?.debug_exception || null;

        if (!debugPayload) return null;

        const box = document.createElement('details');
        box.className = 'debug-box';

        const summary = document.createElement('summary');
        summary.textContent = 'Debug details';
        box.appendChild(summary);

        const pre = document.createElement('pre');
        try {
            pre.textContent = JSON.stringify(debugPayload, null, 2);
        } catch (error) {
            pre.textContent = String(debugPayload);
        }

        box.appendChild(pre);

        return box;
    }

    function appendProductCards(products) {
        if (!Array.isArray(products) || !products.length) return null;

        const cards = document.createElement('div');
        cards.className = 'cards';

        products.forEach(product => {
            const card = document.createElement('a');
            card.className = 'card';
            card.href = product.product_url || product.listing_url || 'https://www.mustakshif.com/list-of-products';
            card.target = '_blank';
            card.rel = 'noopener noreferrer';

            const imageUrl = product.image_url || '';

            if (imageUrl) {
                const image = document.createElement('img');
                image.src = imageUrl;
                image.alt = product.name || 'Product';
                image.loading = 'lazy';
                image.onerror = function () {
                    this.replaceWith(createEmptyImageNode());
                };
                card.appendChild(image);
            } else {
                card.appendChild(createEmptyImageNode());
            }

            const body = document.createElement('div');
            body.className = 'card-body';

            const title = document.createElement('h3');
            title.className = 'card-title';
            title.textContent = product.name || 'Product';
            body.appendChild(title);

            appendMetaText(body, 'Brand', product.brand);
            appendMetaText(body, 'Barcode', product.barcode);
            appendMetaText(body, 'Origin', product.origin);

            if (product.main_category1) {
                appendMetaText(body, 'Category', product.main_category1);
            } else if (product.main_category) {
                appendMetaText(body, 'Category', product.main_category);
            } else if (product.category) {
                appendMetaText(body, 'Category', product.category);
            }

            const resolvedDecision = extractProductDecision(product);

            const badge = document.createElement('span');
            badge.className = 'badge ' + getDecisionClass(resolvedDecision);
            badge.textContent = getDecisionText(resolvedDecision);
            body.appendChild(badge);

            card.appendChild(body);
            cards.appendChild(card);
        });

        return cards;
    }

    function appendMessage(role, text, data = null, userImageUrl = null, isSystem = false) {
        const wrap = document.createElement('div');
        wrap.className = 'message ' + (isSystem ? 'system' : role);

        if (role === 'user' && userImageUrl) {
            const preview = document.createElement('div');
            preview.className = 'user-preview';

            const img = document.createElement('img');
            img.src = userImageUrl;
            img.alt = 'Uploaded image';

            preview.appendChild(img);
            wrap.appendChild(preview);
        }

        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.textContent = escapeText(text);
        wrap.appendChild(bubble);

        const meta = document.createElement('div');
        meta.className = 'message-meta';
        meta.textContent = isSystem ? 'System' : (role === 'user' ? 'You' : 'Assistant');
        wrap.appendChild(meta);

        if (!isSystem && role === 'bot' && data && typeof data === 'object') {
            const contextInfo = appendContextInfo(data);
            if (contextInfo) wrap.appendChild(contextInfo);

            const cards = appendProductCards(data.products || []);
            if (cards) wrap.appendChild(cards);

            const debugInfo = appendDebugInfo(data);
            if (debugInfo) wrap.appendChild(debugInfo);

        }

        chatBox.appendChild(wrap);
        scrollToBottom();
    }

    function appendTyping() {
        removeTyping();

        const wrap = document.createElement('div');
        wrap.className = 'message bot';
        wrap.id = 'typingBox';

        const bubble = document.createElement('div');
        bubble.className = 'bubble';

        const text = document.createElement('div');
        text.className = 'typing';
        text.innerHTML = `
            <span>Checking product information</span>
            <span class="typing-dots">
                <span></span><span></span><span></span>
            </span>
        `;

        bubble.appendChild(text);
        wrap.appendChild(bubble);
        chatBox.appendChild(wrap);
        scrollToBottom();
    }

    function removeTyping() {
        const el = document.getElementById('typingBox');
        if (el) el.remove();
    }

    function setSendingState(sending) {
        isSending = sending;
        sendBtn.disabled = sending;
        input.disabled = sending;
        imageInput.disabled = sending;
        removeImageBtn.disabled = sending;
        enableLocationBtn.disabled = sending;
        clearLocationBtn.disabled = sending;

        if (sending) {
            sendBtn.textContent = 'Sending...';
            uploadLabel.classList.add('disabled');
        } else {
            sendBtn.textContent = 'Send';
            uploadLabel.classList.remove('disabled');
        }
    }

    function resetFormState() {
        input.value = '';
        imageInput.value = '';
        setSelectedImagePreview(null);
        setSendingState(false);
        input.focus();
    }

    async function saveLocationPreference(payload) {
        const response = await fetch('{{ route('chat.location.save') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload)
        });

        return await response.json();
    }

    async function clearLocationPreferenceRequest() {
        const response = await fetch('{{ route('chat.location.clear') }}', {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            }
        });

        return await response.json();
    }

    async function reverseGeocodeCountry(latitude, longitude) {
        const response = await fetch(
            `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${latitude}&lon=${longitude}&zoom=3&addressdetails=1`,
            {
                headers: {
                    'Accept': 'application/json'
                }
            }
        );

        if (!response.ok) {
            throw new Error('Failed to detect location details.');
        }

        return await response.json();
    }

    imageInput.addEventListener('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        setSelectedImagePreview(file);
    });

    removeImageBtn.addEventListener('click', function () {
        if (isSending) return;
        imageInput.value = '';
        setSelectedImagePreview(null);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (!isSending) {
                form.requestSubmit();
            }
        }
    });

    enableLocationBtn.addEventListener('click', function () {
        if (!navigator.geolocation) {
            appendMessage('bot', 'Your browser does not support geolocation.', null, null, true);
            return;
        }

        enableLocationBtn.disabled = true;
        enableLocationBtn.textContent = 'Detecting...';

        navigator.geolocation.getCurrentPosition(
            async function (position) {
                try {
                    const latitude = position.coords.latitude;
                    const longitude = position.coords.longitude;

                    const geoData = await reverseGeocodeCountry(latitude, longitude);

                    const country = geoData?.address?.country || '';
                    const countryCode = (geoData?.address?.country_code || '').toUpperCase();

                    if (!country) {
                        appendMessage('bot', 'I could not detect your country from location.', null, null, true);
                        return;
                    }

                    const result = await saveLocationPreference({
                        country: country,
                        country_code: countryCode,
                        latitude: latitude,
                        longitude: longitude
                    });

                    if (result.success) {
                        setLocationStatus(country);
                        appendMessage(
                            'bot',
                            `Location preference enabled. I will now prioritize products from ${country} when possible.`,
                            null,
                            null,
                            true
                        );
                    } else {
                        appendMessage('bot', 'Failed to save location preference.', null, null, true);
                    }
                } catch (error) {
                    appendMessage('bot', 'Failed to detect and save your location preference.', null, null, true);
                } finally {
                    enableLocationBtn.disabled = false;
                    enableLocationBtn.textContent = 'Enable Location';
                }
            },
            function () {
                appendMessage('bot', 'Location permission was denied or unavailable.', null, null, true);
                enableLocationBtn.disabled = false;
                enableLocationBtn.textContent = 'Enable Location';
            },
            {
                enableHighAccuracy: false,
                timeout: 10000,
                maximumAge: 300000
            }
        );
    });

    clearLocationBtn.addEventListener('click', async function () {
        clearLocationBtn.disabled = true;
        clearLocationBtn.textContent = 'Clearing...';

        try {
            const result = await clearLocationPreferenceRequest();

            if (result.success) {
                setLocationStatus(null);
                appendMessage(
                    'bot',
                    'Location preference cleared. Results will now appear without location priority.',
                    null,
                    null,
                    true
                );
            } else {
                appendMessage('bot', 'Could not clear location preference.', null, null, true);
            }
        } catch (error) {
            appendMessage('bot', 'Failed to clear location preference.', null, null, true);
        } finally {
            clearLocationBtn.disabled = false;
            clearLocationBtn.textContent = 'Clear Location';
        }
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        if (isSending) return;

        const message = input.value.trim();
        const image = imageInput.files && imageInput.files[0] ? imageInput.files[0] : null;

        if (!message && !image) {
            appendMessage('bot', 'Please type a message or upload a product image.', null, null, true);
            return;
        }

        const userImageUrl = image ? URL.createObjectURL(image) : null;
        appendMessage('user', message || '[Image uploaded]', null, userImageUrl);

        setSendingState(true);
        appendTyping();

        try {
            const formData = new FormData();

            if (message) {
                formData.append('message', message);
            }

            if (image) {
                formData.append('image', image);
            }

            const response = await fetch('{{ route('chat.send') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: formData
            });

            let result = null;

            try {
                result = await response.json();
            } catch (jsonError) {
                result = null;
            }

            removeTyping();

            if (!response.ok) {
                appendMessage(
                    'bot',
                    result?.reply || 'Something went wrong while checking the product database.',
                    result?.data || { status: 'error', products: [] }
                );
                resetFormState();
                if (userImageUrl) URL.revokeObjectURL(userImageUrl);
                return;
            }

            appendMessage(
                'bot',
                result?.reply || 'Product information is not available yet.',
                result?.data || null
            );

            resetFormState();

            if (userImageUrl) {
                URL.revokeObjectURL(userImageUrl);
            }
        } catch (error) {
            removeTyping();
            appendMessage(
                'bot',
                'Something went wrong while checking the product database.',
                { status: 'error', products: [] }
            );
            resetFormState();

            if (userImageUrl) {
                URL.revokeObjectURL(userImageUrl);
            }
        }

      
    });

    scrollToBottom();
</script>
</body>
</html>