/**
 * Crosspost to Loops — Admin JS
 */
(function ($) {
    'use strict';

    /* ------------------------------------------------------------------ */
    /* Access token eye toggle                                              */
    /* ------------------------------------------------------------------ */
    $(document).on('click', '#loops_token_eye', function () {
        const $input   = $('#loops_access_token');
        const $eyeOn   = $('#loops_eye_icon');
        const $eyeOff  = $('#loops_eye_off_icon');
        const isHidden = $input.attr('type') === 'password';
        $input.attr('type', isHidden ? 'text' : 'password');
        $eyeOn.toggle(!isHidden);
        $eyeOff.toggle(isHidden);
    });

    /* ------------------------------------------------------------------ */
    /* Token verification + connected account card update                   */
    /* ------------------------------------------------------------------ */
    $(document).on('click', '#loops_verify_token', function () {
        const $btn    = $(this);
        const $status = $('#loops_token_status');
        const token   = $('#loops_access_token').val().trim();
        const url     = $('[name="wp_loops_settings[instance_url]"]').val().trim() || 'https://loops.video';

        if (!token) { $status.css('color', '#c53030').text('⚠ Please enter a token first.'); return; }

        $btn.prop('disabled', true).text(crosspostToLoops.i18n.verifying);
        $status.css('color', '#666').text('…');

        $.post(crosspostToLoops.ajaxUrl, { action: 'loops_verify_token', nonce: crosspostToLoops.verifyTokenNonce, token, instance_url: url })
        .done(function (res) {
            if (res.success) {
                const d = res.data;
                $status.css('color', '#276749').text('✅ ' + d.message);
                const avatarHtml = d.avatar
                    ? `<img src="${d.avatar}" alt="" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:1px solid #eee">`
                    : `<div style="width:48px;height:48px;border-radius:50%;background:#6c63ff;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;font-weight:600">${(d.username||'?')[0].toUpperCase()}</div>`;
                $('#loops-connected-account').html(`
                    <div style="display:inline-flex;align-items:center;gap:14px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 16px;box-shadow:0 1px 3px rgba(0,0,0,.06)">
                        ${avatarHtml}
                        <div>
                            <div style="font-weight:600;font-size:15px;color:#1a1a1a">${d.name||d.username}</div>
                            <div style="color:#666;font-size:13px">@${d.username} &nbsp;·&nbsp; ${d.post_count} video${d.post_count!==1?'s':''} &nbsp;·&nbsp; ${d.follower_count} follower${d.follower_count!==1?'s':''}</div>
                            <div style="margin-top:4px"><span style="display:inline-block;background:#e6f4ea;color:#1e7e34;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px">✓ Connected</span></div>
                        </div>
                        <div style="margin-left:8px"><a href="${d.url||url}" target="_blank" class="button button-secondary" style="font-size:12px">View profile →</a></div>
                    </div>`);
            } else {
                $status.css('color', '#c53030').text('❌ ' + res.data.message);
            }
        })
        .fail(() => $status.css('color', '#c53030').text('❌ Request failed.'))
        .always(() => $btn.prop('disabled', false).text('Verify token'));
    });

    /* ------------------------------------------------------------------ */
    /* Disconnect account                                                    */
    /* ------------------------------------------------------------------ */
    $(document).on('click', '#loops-disconnect-btn', function () {
        const $btn = $(this);
        const connectUrl = $btn.data('connect-url');
        if (!confirm(crosspostToLoops.i18n.confirmDisconnect)) return;
        $btn.prop('disabled', true).text(crosspostToLoops.i18n.disconnecting);
        $.post(crosspostToLoops.ajaxUrl, { action: 'loops_disconnect', nonce: crosspostToLoops.disconnectNonce })
        .done(function (res) {
            if (res.success) {
                $('#loops-connected-account').html(`
                    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px 28px;max-width:520px;box-shadow:0 1px 3px rgba(0,0,0,.06)">
                        <h3 style="margin:0 0 8px;font-size:16px">Connect your Loops.video account</h3>
                        <p style="color:#666;margin:0 0 16px;font-size:13px">Disconnected. Click below to reconnect.</p>
                        <a href="${connectUrl}" class="button button-primary" style="font-size:14px;height:36px;line-height:34px;padding:0 18px">🔗 Connect to Loops.video</a>
                    </div>`);
            }
        })
        .fail(() => { $btn.prop('disabled', false).text('Disconnect'); alert('Disconnect failed. Please try again.'); });
    });

    /* ------------------------------------------------------------------ */
    /* Test crosspost runner                                                 */
    /* ------------------------------------------------------------------ */
    $(document).on('click', '#ctl-run-test', function () {
        const $btn     = $(this);
        const postId   = $('#ctl_test_post_id').val();
        const caption  = $('#ctl_test_caption').val().trim();
        const $results = $('#ctl-test-results');
        const $steps   = $('#ctl-test-steps');
        const $final   = $('#ctl-test-final');

        if (!postId) {
            alert('Please select a post first.');
            return;
        }

        // Reset UI
        $steps.html('');
        $final.html('');
        $results.show();
        $btn.prop('disabled', true).text('Running…');

        // Show a "running" spinner step
        $steps.append(stepHtml(null, 'Connecting to Loops.video…'));

        $.post(crosspostToLoops.ajaxUrl, {
            action:   'loops_test_crosspost',
            nonce:    $btn.data('nonce'),
            post_id:  postId,
            caption:  caption,
        })
        .done(function (res) {
            $steps.html('');
            const steps = res.data?.steps || res.success ? (res.data.steps || []) : [];
            steps.forEach(s => $steps.append(stepHtml(s.ok, s.text)));

            if (res.success) {
                const url = res.data.url;
                $final.html(`
                    <div style="background:#f0fff4;border:1px solid #68d391;border-radius:6px;padding:12px 16px">
                        <div style="color:#276749;font-size:15px;margin-bottom:6px">✅ ${res.data.message}</div>
                        ${url ? `<a href="${url}" target="_blank" class="button button-secondary" style="font-size:12px">View on Loops.video →</a>
                        <p style="color:#555;font-size:12px;margin:8px 0 0">Remember to delete this test video from Loops if you don't want it there permanently.</p>` : ''}
                    </div>`);
            } else {
                $final.html(`
                    <div style="background:#fff5f5;border:1px solid #fc8181;border-radius:6px;padding:12px 16px;color:#c53030">
                        ❌ ${res.data?.message || 'Test failed.'}
                    </div>`);
            }
        })
        .fail(() => {
            $steps.html('');
            $final.html('<div style="color:#c53030">❌ Request failed. Check your browser console.</div>');
        })
        .always(() => $btn.prop('disabled', false).text('▶ Run test'));
    });

    function stepHtml(ok, text) {
        const icon = ok === null ? '<span style="display:inline-block;width:16px">⏳</span>'
                   : ok         ? '<span style="color:#276749">✓</span>'
                                : '<span style="color:#c53030">✗</span>';
        return `<div style="display:flex;gap:8px;align-items:baseline;padding:4px 0;font-size:13px;border-bottom:1px solid #f0f0f0">
                    <span style="flex-shrink:0;width:18px;text-align:center">${icon}</span>
                    <span style="color:${ok===false?'#c53030':'#333'}">${text}</span>
                </div>`;
    }

    /* ------------------------------------------------------------------ */
    /* Manual crosspost (post editor sidebar)                               */
    /* ------------------------------------------------------------------ */
    $(document).on('click', '#loops-crosspost-btn', function () {
        const $btn    = $(this);
        const $result = $('#loops-crosspost-result');
        const postId  = $btn.data('post-id');
        const nonce   = $btn.data('nonce');
        $btn.prop('disabled', true).text(crosspostToLoops.i18n.crossposting);
        $result.removeClass('success error').text('');
        $.post(crosspostToLoops.ajaxUrl, { action: 'loops_manual_crosspost', post_id: postId, nonce })
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').html('✅ ' + res.data.message + (res.data.video_url ? ` <a href="${res.data.video_url}" target="_blank">View on Loops →</a>` : ''));
                $btn.text('🔄 Re-crosspost to Loops.video');
            } else {
                $result.addClass('error').text('❌ ' + res.data.message);
                $btn.text('🚀 Crosspost to Loops.video');
            }
        })
        .fail(() => { $result.addClass('error').text('❌ Request failed.'); $btn.text('🚀 Crosspost to Loops.video'); })
        .always(() => $btn.prop('disabled', false));
    });

    /* ------------------------------------------------------------------ */
    /* Clear debug log                                                       */
    /* ------------------------------------------------------------------ */
    $(document).on('click', '#loops_clear_log', function () {
        const $btn = $(this);
        if (!confirm('Clear all log entries?')) return;
        $btn.prop('disabled', true).text('Clearing…');
        $.post(crosspostToLoops.ajaxUrl, { action: 'loops_clear_log', nonce: $btn.data('nonce') })
        .done(res => { if (res.success) $('#loops-log-wrap').html('<div style="color:#858585;padding:4px 0">Log cleared.</div>'); })
        .always(() => $btn.prop('disabled', false).text('Clear log'));
    });

}(jQuery));
