<!DOCTYPE html>
<html lang="pt-PT" data-preview-theme="{{ $selectedTheme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contiu · emails</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f7fb;
            --panel: #ffffff;
            --panel-border: #d4d4d8;
            --text: #18181b;
            --muted: #71717a;
            --control: #ffffff;
            --control-border: #a1a1aa;
            --canvas: #e4e4e7;
            --shadow: 0 12px 28px rgba(24, 24, 27, 0.12);
        }

        html[data-preview-theme="dark"] {
            color-scheme: dark;
            --bg: #0f172a;
            --panel: #18181b;
            --panel-border: #3f3f46;
            --text: #fafafa;
            --muted: #a1a1aa;
            --control: #27272a;
            --control-border: #52525b;
            --canvas: #09090b;
            --shadow: 0 18px 34px rgba(0, 0, 0, 0.42);
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .shell {
            min-height: 100vh;
            display: grid;
            grid-template-rows: auto 1fr;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 14px;
            padding: 18px;
            background: var(--panel);
            border-bottom: 1px solid var(--panel-border);
            box-shadow: var(--shadow);
            z-index: 1;
        }

        .toolbar-title {
            min-width: 180px;
            margin-right: auto;
        }

        h1 {
            margin: 0;
            font-size: 20px;
            line-height: 1.2;
        }

        .subtitle {
            margin-top: 4px;
            color: var(--muted);
            font-size: 13px;
        }

        label {
            display: grid;
            gap: 6px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        select,
        input,
        button.button,
        a.button {
            min-height: 40px;
            border: 1px solid var(--control-border);
            border-radius: 6px;
            background: var(--control);
            color: var(--text);
            font: inherit;
        }

        select {
            min-width: 180px;
            padding: 0 36px 0 12px;
        }

        input {
            width: 100%;
            padding: 0 12px;
        }

        button.button,
        a.button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 14px;
            text-decoration: none;
            font-weight: 600;
        }

        button.button {
            cursor: pointer;
        }

        button.button.primary {
            border-color: #18181b;
            background: #18181b;
            color: #ffffff;
        }

        html[data-preview-theme="dark"] button.button.primary {
            border-color: #fafafa;
            background: #fafafa;
            color: #18181b;
        }

        .preview-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            padding: 22px;
            background: var(--canvas);
        }

        .preview-subject {
            width: min(100%, 960px);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--panel-border);
            border-radius: 8px;
            background: var(--panel);
            color: var(--text);
            padding: 12px 14px;
            box-shadow: var(--shadow);
        }

        .preview-subject-label {
            flex: 0 0 auto;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .preview-subject-value {
            min-width: 0;
            font-size: 14px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        iframe {
            width: min(100%, 960px);
            min-height: calc(100vh - 128px);
            margin: 0 auto;
            border: 1px solid var(--panel-border);
            border-radius: 8px;
            background: #ffffff;
            box-shadow: var(--shadow);
        }

        .modal {
            position: fixed;
            inset: 0;
            display: grid;
            place-items: center;
            padding: 18px;
            background: rgba(15, 23, 42, 0.62);
            z-index: 10;
        }

        .modal[hidden] {
            display: none;
        }

        .modal-panel {
            width: min(100%, 520px);
            display: grid;
            gap: 16px;
            border: 1px solid var(--panel-border);
            border-radius: 8px;
            background: var(--panel);
            color: var(--text);
            padding: 20px;
            box-shadow: var(--shadow);
        }

        .modal-title {
            margin: 0;
            font-size: 18px;
            line-height: 1.25;
        }

        .modal-summary {
            display: grid;
            gap: 10px;
            margin: 0;
            padding: 12px;
            border: 1px solid var(--panel-border);
            border-radius: 6px;
            background: var(--canvas);
            font-size: 13px;
        }

        .modal-summary-row {
            display: grid;
            gap: 3px;
        }

        .modal-summary dt {
            color: var(--muted);
            font-weight: 600;
            text-transform: uppercase;
        }

        .modal-summary dd {
            margin: 0;
            font-weight: 600;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .send-status {
            min-height: 20px;
            color: var(--muted);
            font-size: 13px;
        }

        .send-status[data-tone="success"] {
            color: #15803d;
        }

        .send-status[data-tone="error"] {
            color: #dc2626;
        }

        @media (max-width: 720px) {
            .toolbar {
                align-items: stretch;
            }

            .toolbar-title,
            label,
            select,
            button.button,
            a.button {
                width: 100%;
            }

            iframe {
                min-height: calc(100vh - 280px);
            }

            .preview-subject {
                align-items: flex-start;
                flex-direction: column;
                gap: 4px;
            }
        }
    </style>
</head>
<body>
    <main class="shell" data-mail-preview-viewer>
        <div class="toolbar" id="mail-preview-controls">
            <div class="toolbar-title">
                <h1>Contiu · emails</h1>
                <div class="subtitle">Local only · example data from the first account</div>
            </div>

            <label>
                Template
                <select name="template" id="template-select">
                    @foreach ($templates as $template)
                        <option value="{{ $template['key'] }}" @selected($template['key'] === $selectedTemplate)>
                            {{ $template['label'] }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Language
                <select name="locale" id="locale-select">
                    @foreach ($localeOptions as $locale => $label)
                        <option value="{{ $locale }}" @selected($locale === $selectedLocale)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Mode
                <select name="theme" id="theme-select">
                    @foreach ($themeOptions as $theme => $label)
                        <option value="{{ $theme }}" @selected($theme === $selectedTheme)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </label>

            <button class="button primary" id="send-preview-button" type="button">Send</button>
            <a class="button" id="open-preview-link" href="#" target="_blank" rel="noopener">Open</a>
        </div>

        <section class="preview-wrap">
            <header class="preview-subject" data-mail-preview-subject>
                <span class="preview-subject-label">Subject</span>
                <span class="preview-subject-value" id="subject-value">{{ $selectedSubject }}</span>
            </header>
            <iframe id="mail-preview-frame" title="Email preview"></iframe>
        </section>

        <div class="modal" id="send-preview-modal" data-mail-preview-send-modal hidden>
            <form class="modal-panel" id="send-preview-form">
                @csrf

                <input type="hidden" name="template" id="send-template">
                <input type="hidden" name="locale" id="send-locale">

                <h2 class="modal-title">Send preview email</h2>

                <dl class="modal-summary">
                    <div class="modal-summary-row">
                        <dt>Template</dt>
                        <dd id="send-template-label"></dd>
                    </div>
                    <div class="modal-summary-row">
                        <dt>Language</dt>
                        <dd id="send-locale-label"></dd>
                    </div>
                    <div class="modal-summary-row">
                        <dt>Subject</dt>
                        <dd id="send-subject-label"></dd>
                    </div>
                </dl>

                <label>
                    Recipient email
                    <input
                        type="email"
                        name="email"
                        id="send-email"
                        value="{{ $defaultRecipient }}"
                        required
                    >
                </label>

                <div class="send-status" id="send-preview-status" role="status" aria-live="polite"></div>

                <div class="modal-actions">
                    <button class="button" type="button" id="cancel-send-preview">Cancel</button>
                    <button class="button primary" type="submit" id="confirm-send-preview">Send email</button>
                </div>
            </form>
        </div>
    </main>

    <script>
        const templates = {!! Illuminate\Support\Js::from($templates) !!};
        const localeOptions = {!! Illuminate\Support\Js::from($localeOptions) !!};
        const metadataUrl = {!! Illuminate\Support\Js::from($metadataUrl) !!};
        const sendUrl = {!! Illuminate\Support\Js::from($sendUrl) !!};
        const csrfToken = {!! Illuminate\Support\Js::from(csrf_token()) !!};
        const templateSelect = document.getElementById('template-select');
        const localeSelect = document.getElementById('locale-select');
        const themeSelect = document.getElementById('theme-select');
        const frame = document.getElementById('mail-preview-frame');
        const openLink = document.getElementById('open-preview-link');
        const subjectValue = document.getElementById('subject-value');
        const sendButton = document.getElementById('send-preview-button');
        const sendModal = document.getElementById('send-preview-modal');
        const sendForm = document.getElementById('send-preview-form');
        const sendTemplateInput = document.getElementById('send-template');
        const sendLocaleInput = document.getElementById('send-locale');
        const sendTemplateLabel = document.getElementById('send-template-label');
        const sendLocaleLabel = document.getElementById('send-locale-label');
        const sendSubjectLabel = document.getElementById('send-subject-label');
        const sendEmail = document.getElementById('send-email');
        const sendStatus = document.getElementById('send-preview-status');
        const cancelSendButton = document.getElementById('cancel-send-preview');
        const confirmSendButton = document.getElementById('confirm-send-preview');
        let subjectRequest = 0;

        function selectedTemplate() {
            return templates.find((template) => template.key === templateSelect.value) ?? templates[0];
        }

        function previewUrl() {
            const template = selectedTemplate();
            const url = new URL(template.url, window.location.origin);

            url.searchParams.set('locale', localeSelect.value);

            return url;
        }

        async function syncSubject() {
            const currentRequest = ++subjectRequest;
            const url = new URL(metadataUrl, window.location.origin);

            url.searchParams.set('template', templateSelect.value);
            url.searchParams.set('locale', localeSelect.value);
            subjectValue.textContent = 'Loading...';

            try {
                const response = await fetch(url, {
                    headers: {
                        Accept: 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error('Unable to load subject.');
                }

                const data = await response.json();

                if (currentRequest === subjectRequest) {
                    subjectValue.textContent = data.subject || '';
                }
            } catch (error) {
                if (currentRequest === subjectRequest) {
                    subjectValue.textContent = 'Unable to load subject';
                }
            }
        }

        function syncPreview() {
            const url = previewUrl();

            document.documentElement.dataset.previewTheme = themeSelect.value;
            frame.src = url.toString();
            openLink.href = url.toString();
            syncSubject();

            const browserUrl = new URL(window.location.href);
            browserUrl.searchParams.set('template', templateSelect.value);
            browserUrl.searchParams.set('locale', localeSelect.value);
            browserUrl.searchParams.set('theme', themeSelect.value);

            window.history.replaceState({}, '', browserUrl);
        }

        function openSendModal() {
            const template = selectedTemplate();

            sendTemplateInput.value = templateSelect.value;
            sendLocaleInput.value = localeSelect.value;
            sendTemplateLabel.textContent = template.label;
            sendLocaleLabel.textContent = localeOptions[localeSelect.value] || localeSelect.value;
            sendSubjectLabel.textContent = subjectValue.textContent;
            sendStatus.textContent = '';
            sendStatus.dataset.tone = '';
            sendModal.hidden = false;
            sendEmail.focus();
            sendEmail.select();
        }

        function closeSendModal() {
            sendModal.hidden = true;
        }

        async function sendPreview(event) {
            event.preventDefault();

            const formData = new FormData(sendForm);

            formData.set('template', templateSelect.value);
            formData.set('locale', localeSelect.value);
            sendStatus.dataset.tone = '';
            sendStatus.textContent = 'Sending...';
            confirmSendButton.disabled = true;

            try {
                const response = await fetch(sendUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: formData,
                });

                const data = await response.json();

                if (!response.ok) {
                    const errors = data.errors ? Object.values(data.errors).flat().join(' ') : null;

                    throw new Error(errors || data.message || 'Unable to send preview email.');
                }

                sendStatus.dataset.tone = 'success';
                sendStatus.textContent = data.message || 'Preview email sent.';
            } catch (error) {
                sendStatus.dataset.tone = 'error';
                sendStatus.textContent = error.message || 'Unable to send preview email.';
            } finally {
                confirmSendButton.disabled = false;
            }
        }

        templateSelect.addEventListener('change', syncPreview);
        localeSelect.addEventListener('change', syncPreview);
        themeSelect.addEventListener('change', syncPreview);
        sendButton.addEventListener('click', openSendModal);
        cancelSendButton.addEventListener('click', closeSendModal);
        sendModal.addEventListener('click', (event) => {
            if (event.target === sendModal) {
                closeSendModal();
            }
        });
        sendForm.addEventListener('submit', sendPreview);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !sendModal.hidden) {
                closeSendModal();
            }
        });

        syncPreview();
    </script>
</body>
</html>
