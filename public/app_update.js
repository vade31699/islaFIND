// ============================================================
// app_update.js — the in-app "App update" button
//
// The Android app (a Bubblewrap TWA) opens the site with
// ?appv=<versionCode>&isla_app=1. Those values are remembered in
// localStorage, so every page knows it is running inside the app.
//
// When /app/version.json advertises a newer versionCode than the
// installed one, this script reveals the "App update" row in
// Settings and a small floating prompt. Tapping either opens
// islafind://update, which the app's UpdateActivity handles:
// it downloads the APK and hands it to Android's installer.
//
// Chrome falls back to the plain APK download if the app does not
// handle the intent (S.browser_fallback_url).
// ============================================================

(function () {
    'use strict';

    var VERSION_KEY = 'isla_app_version';
    var TWA_KEY     = 'isla_is_twa';
    var VERSION_URL = '/app/version.json';
    var FALLBACK_APK = '/app/islafind-latest.apk';

    function storageGet(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function storageSet(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {
            // Private mode: the row simply stays hidden.
        }
    }

    // The launch URL is the only place the native version is exposed.
    function captureLaunch() {
        var params;
        try {
            params = new URLSearchParams(window.location.search);
        } catch (e) {
            return;
        }

        var version = params.get('appv');
        if (version && /^[0-9]+$/.test(version)) {
            storageSet(VERSION_KEY, version);
            storageSet(TWA_KEY, '1');
        }
    }

    function installedCode() {
        var version = parseInt(storageGet(VERSION_KEY) || '0', 10);
        return isNaN(version) ? 0 : version;
    }

    // localStorage is shared with Chrome, so also require standalone
    // display mode: that is the app, not a tab on the same site.
    function inApp() {
        if (storageGet(TWA_KEY) !== '1') {
            return false;
        }
        try {
            return window.matchMedia('(display-mode: standalone)').matches
                || window.matchMedia('(display-mode: fullscreen)').matches;
        } catch (e) {
            return true;
        }
    }

    function fetchLatest() {
        return fetch(VERSION_URL, { cache: 'no-store' }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    function launchUpdater(latest) {
        var fallback = (latest && latest.apkUrl) || FALLBACK_APK;
        var intent = 'intent://update#Intent;'
            + 'scheme=islafind;'
            + 'package=com.vade31699.islafind;'
            + 'S.browser_fallback_url=' + encodeURIComponent(fallback) + ';'
            + 'end';
        window.location.href = intent;
    }

    function setStatus(text) {
        var nodes = document.querySelectorAll('[data-app-update-status]');
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].textContent = text;
        }
    }

    function versionLabel(latest) {
        return latest.versionName || ('v' + latest.versionCode);
    }

    function revealRows() {
        var rows = document.querySelectorAll('[data-app-update]');
        for (var i = 0; i < rows.length; i++) {
            rows[i].hidden = false;
        }
        return rows;
    }

    function showChip(latest) {
        if (document.querySelector('[data-app-update]')) {
            return; // The Settings row already carries the message.
        }
        if (document.querySelector('[data-app-update-chip]')) {
            return;
        }

        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'app-update-chip';
        chip.setAttribute('data-app-update-chip', '');
        chip.textContent = 'Update islaFIND to ' + versionLabel(latest);
        chip.addEventListener('click', function () {
            launchUpdater(latest);
        });
        document.body.appendChild(chip);
    }

    function handleRowClick(event) {
        event.preventDefault();
        setStatus('Checking\u2026');
        fetchLatest().then(function (latest) {
            if (latest.versionCode > installedCode()) {
                launchUpdater(latest);
            } else {
                setStatus('Up to date');
            }
        }).catch(function () {
            // Let the native checker explain the connection problem.
            launchUpdater(null);
        });
    }

    function wire() {
        captureLaunch();

        if (!inApp()) {
            return;
        }

        var rows = revealRows();
        for (var i = 0; i < rows.length; i++) {
            rows[i].addEventListener('click', handleRowClick);
        }

        setStatus('Checking\u2026');
        fetchLatest().then(function (latest) {
            if (latest.versionCode > installedCode()) {
                setStatus('Update to ' + versionLabel(latest));
                showChip(latest);
            } else {
                setStatus('Up to date');
            }
        }).catch(function () {
            setStatus('Check for updates');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
})();
