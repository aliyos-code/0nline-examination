/* ==========================================================================
   Online Examination and Result Management System - client-side scripts
   ========================================================================== */
(function () {
    'use strict';

    // -----------------------------------------------------------------
    // Generic: confirm before destructive actions
    // -----------------------------------------------------------------
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (event) {
            var message = el.getAttribute('data-confirm') || 'Are you sure?';
            if (!window.confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    });

    // Loading indicator on submit buttons (exam form handles its own).
    document.querySelectorAll('form').forEach(function (form) {
        if (form.id === 'examForm') { return; }
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"], button:not([type])');
            if (btn && !btn.disabled && !btn.getAttribute('data-no-loading')) {
                btn.disabled = true;
                btn.dataset.original = btn.innerHTML;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading...';
            }
        });
    });

    // -----------------------------------------------------------------
    // Examination engine (only active on the take_exam page)
    // -----------------------------------------------------------------
    var app = document.getElementById('examApp');
    if (!app) {
        return;
    }

    var total       = parseInt(app.getAttribute('data-total'), 10) || 0;
    var attemptId   = app.getAttribute('data-attempt-id');
    var saveUrl     = app.getAttribute('data-save-url');
    var remaining   = parseInt(app.getAttribute('data-remaining'), 10) || 0; // seconds until deadline
    var csrfToken   = app.getAttribute('data-csrf');
    var logUrl      = app.getAttribute('data-log-url');

    // -----------------------------------------------------------------
    // Proctoring: report integrity events to the server (best-effort).
    // Duplicate events within a short window are coalesced.
    // -----------------------------------------------------------------
    var lastLogged = {};
    function logEvent(type, detail) {
        if (!logUrl) {
            return;
        }
        var nowMs = Date.now();
        if (lastLogged[type] && (nowMs - lastLogged[type]) < 3000) {
            return;
        }
        lastLogged[type] = nowMs;
        var body = 'csrf_token=' + encodeURIComponent(csrfToken) +
                   '&attempt_id=' + encodeURIComponent(attemptId) +
                   '&event_type=' + encodeURIComponent(type) +
                   '&detail=' + encodeURIComponent(detail || '');
        fetch(logUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
            credentials: 'same-origin'
        }).catch(function () { /* ignore transient network errors */ });
    }

    if (logUrl) {
        window.addEventListener('blur', function () { logEvent('focus_loss', 'window blur'); });
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { logEvent('focus_loss', 'tab hidden'); }
        });
        document.addEventListener('copy', function () { logEvent('copy_attempt', ''); });
        document.addEventListener('paste', function () { logEvent('paste_attempt', ''); });
        document.addEventListener('contextmenu', function () { logEvent('context_menu', ''); });
    }

    var questions   = Array.prototype.slice.call(app.querySelectorAll('.exam-question'));
    var navButtons  = Array.prototype.slice.call(app.querySelectorAll('.qnav-btn'));
    var timerEl     = document.getElementById('timer');
    var progressBar = document.getElementById('examProgress');
    var progressTxt = document.getElementById('examProgressText');
    var prevBtn     = document.getElementById('btnPrev');
    var nextBtn     = document.getElementById('btnNext');
    var submitBtn   = document.getElementById('btnSubmit');
    var form        = document.getElementById('examForm');

    var current = 0;
    var submitting = false;
    var warned = false;

    function showQuestion(index) {
        if (index < 0 || index >= questions.length) {
            return;
        }
        questions.forEach(function (q, i) {
            q.classList.toggle('d-none', i !== index);
        });
        navButtons.forEach(function (b, i) {
            b.classList.toggle('current', i === index);
        });
        current = index;
        prevBtn.disabled = (current === 0);
        nextBtn.disabled = (current === questions.length - 1);
    }

    function updateProgress() {
        var answered = 0;
        questions.forEach(function (q) {
            if (q.querySelector('input[type=radio]:checked')) {
                answered++;
            }
        });
        var pct = total > 0 ? Math.round((answered / total) * 100) : 0;
        if (progressBar) {
            progressBar.style.width = pct + '%';
            progressBar.setAttribute('aria-valuenow', pct);
        }
        if (progressTxt) {
            progressTxt.textContent = answered + ' of ' + total + ' answered';
        }
        return answered;
    }

    function saveAnswer(questionId, option) {
        if (!saveUrl) {
            return;
        }
        var body = 'csrf_token=' + encodeURIComponent(csrfToken) +
                   '&attempt_id=' + encodeURIComponent(attemptId) +
                   '&question_id=' + encodeURIComponent(questionId) +
                   '&option=' + encodeURIComponent(option);
        // Silent background save so answers survive a refresh / auto-submit.
        fetch(saveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
            credentials: 'same-origin'
        }).catch(function () { /* ignore transient network errors */ });
    }

    // Wire up option selection
    questions.forEach(function (q) {
        var questionId = q.getAttribute('data-question-id');
        var inputs = q.querySelectorAll('input[type=radio]');
        inputs.forEach(function (input) {
            input.addEventListener('change', function () {
                q.querySelectorAll('.option-label').forEach(function (label) {
                    label.classList.remove('selected');
                });
                var label = input.closest('.option-label');
                if (label) {
                    label.classList.add('selected');
                }
                var navIndex = parseInt(q.getAttribute('data-index'), 10);
                if (navButtons[navIndex]) {
                    navButtons[navIndex].classList.add('answered');
                }
                updateProgress();
                saveAnswer(questionId, input.value);
            });
        });
    });

    // Pre-fill navigator state on load
    questions.forEach(function (q, i) {
        if (q.querySelector('input[type=radio]:checked')) {
            if (navButtons[i]) {
                navButtons[i].classList.add('answered');
            }
            var checked = q.querySelector('input[type=radio]:checked');
            var label = checked.closest('.option-label');
            if (label) {
                label.classList.add('selected');
            }
        }
    });

    // Navigation buttons
    if (prevBtn) {
        prevBtn.addEventListener('click', function () { showQuestion(current - 1); });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', function () { showQuestion(current + 1); });
    }
    navButtons.forEach(function (b) {
        b.addEventListener('click', function () {
            showQuestion(parseInt(b.getAttribute('data-index'), 10));
        });
    });

    // Confirmation before submit
    if (form) {
        form.addEventListener('submit', function (event) {
            if (submitting) {
                return;
            }
            var answered = updateProgress();
            var unanswered = total - answered;
            var message = 'You are about to submit your examination. Are you sure?';
            if (unanswered > 0) {
                message = 'You still have ' + unanswered + ' unanswered question(s). ' + message;
            }
            if (!window.confirm(message)) {
                event.preventDefault();
                return;
            }
            submitting = true;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Submitting...';
            }
        });
    }

    // Countdown timer + automatic submission
    function renderTimer(seconds) {
        if (!timerEl) {
            return;
        }
        if (seconds < 0) {
            seconds = 0;
        }
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        timerEl.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        if (seconds <= 60) {
            timerEl.classList.add('low-time', 'text-danger');
        }
    }

    renderTimer(remaining);
    showQuestion(0);
    updateProgress();

    var interval = window.setInterval(function () {
        remaining--;
        renderTimer(remaining);

        if (remaining === 60 && !warned) {
            warned = true;
            window.alert('Warning: 1 minute remaining.');
        }

        if (remaining <= 0) {
            window.clearInterval(interval);
            if (!submitting && form) {
                submitting = true;
                window.alert('Time is up. Your examination will now be submitted automatically.');
                form.submit();
            }
        }
    }, 1000);
})();
