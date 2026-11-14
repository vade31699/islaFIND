/*
 * UpdateActivity.java — in-app APK updater for islaFIND.
 *
 * The website opens islafind://update when the member taps the
 * "App update" row. This activity:
 *
 *   1. fetches /app/version.json from the live site,
 *   2. compares its versionCode with the installed one,
 *   3. downloads the new APK (verifying sha256 when published),
 *   4. hands it to Android's package installer.
 *
 * Android always shows its own installer confirmation. A normal app
 * cannot install an APK silently — that is reserved for Play and for
 * device-owner/MDM apps. Signature must match the installed app and
 * versionCode must increase; otherwise Android rejects the update.
 */
package com.vade31699.islafind;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.ProgressDialog;
import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.widget.Toast;

import androidx.core.content.FileProvider;

import org.json.JSONObject;

import java.io.BufferedInputStream;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.security.MessageDigest;

public class UpdateActivity extends Activity {

    private static final int UNKNOWN_SOURCES_REQUEST = 4101;

    /** Kept while the user is in Settings granting "install unknown apps". */
    private String pendingApkUrl = null;
    private String pendingSha256 = null;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        checkForUpdate();
    }

    /** Installed versionCode, also used by LauncherActivity. */
    static int installedVersionCode(Context context) {
        try {
            PackageInfo info = context.getPackageManager()
                    .getPackageInfo(context.getPackageName(), 0);
            if (Build.VERSION.SDK_INT >= 28) {
                return (int) info.getLongVersionCode();
            }
            return info.versionCode;
        } catch (PackageManager.NameNotFoundException e) {
            return 0;
        }
    }

    private void checkForUpdate() {
        final ProgressDialog progress = new ProgressDialog(this);
        progress.setMessage("Checking for updates\u2026");
        progress.setCancelable(false);
        progress.show();

        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    final JSONObject json = fetchJson(getString(R.string.updateManifestUrl));
                    final int latestCode = json.getInt("versionCode");
                    final String latestName = json.optString("versionName", String.valueOf(latestCode));
                    final String apkUrl = json.getString("apkUrl");
                    final String sha256 = json.optString("sha256", "");
                    final String notes = json.optString("notes", "");

                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            dismissQuietly(progress);
                            if (isFinishing()) {
                                return;
                            }
                            if (latestCode > installedVersionCode(UpdateActivity.this)) {
                                showUpdateDialog(latestName, apkUrl, sha256, notes);
                            } else {
                                finishWithMessage("You're using the latest version of islaFIND.");
                            }
                        }
                    });
                } catch (final Exception e) {
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            dismissQuietly(progress);
                            finishWithMessage("Couldn't check for updates. Please try again later.");
                        }
                    });
                }
            }
        }).start();
    }

    private void showUpdateDialog(final String latestName, final String apkUrl,
                                  final String sha256, String notes) {
        String message = "Version " + latestName + " is available.";
        if (notes != null && !notes.isEmpty()) {
            message += "\n\n" + notes;
        }

        new AlertDialog.Builder(this)
                .setTitle(R.string.updateTitle)
                .setMessage(message)
                .setPositiveButton("Update now", (dialog, which) -> startUpdate(apkUrl, sha256))
                .setNegativeButton("Later", (dialog, which) -> finish())
                .setCancelable(false)
                .show();
    }

    private void startUpdate(String apkUrl, String sha256) {
        if (Build.VERSION.SDK_INT >= 26 && !getPackageManager().canRequestPackageInstalls()) {
            pendingApkUrl = apkUrl;
            pendingSha256 = sha256;

            new AlertDialog.Builder(this)
                    .setTitle("Allow this update")
                    .setMessage("Android needs your permission to install the update. "
                            + "Turn on \u201cAllow from this source\u201d, then come back to islaFIND.")
                    .setPositiveButton("Open settings", (dialog, which) -> {
                        try {
                            startActivityForResult(
                                    new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                                            Uri.parse("package:" + getPackageName())),
                                    UNKNOWN_SOURCES_REQUEST);
                        } catch (ActivityNotFoundException e) {
                            finishWithMessage("Open Settings, allow installs for islaFIND, then try again.");
                        }
                    })
                    .setNegativeButton("Cancel", (dialog, which) -> finish())
                    .setCancelable(false)
                    .show();
            return;
        }

        downloadAndInstall(apkUrl, sha256);
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != UNKNOWN_SOURCES_REQUEST) {
            return;
        }
        if (Build.VERSION.SDK_INT >= 26 && getPackageManager().canRequestPackageInstalls()) {
            downloadAndInstall(pendingApkUrl, pendingSha256);
        } else {
            finishWithMessage("Update cancelled: install permission is still off.");
        }
    }

    private void downloadAndInstall(final String apkUrl, final String sha256) {
        final ProgressDialog progress = new ProgressDialog(this);
        progress.setMessage("Downloading update\u2026");
        progress.setProgressStyle(ProgressDialog.STYLE_HORIZONTAL);
        progress.setIndeterminate(true);
        progress.setCancelable(false);
        progress.show();

        new Thread(new Runnable() {
            @Override
            public void run() {
                final File apk = new File(getCacheDir(), "updates/islafind-update.apk");
                try {
                    File dir = apk.getParentFile();
                    if (dir != null && !dir.exists() && !dir.mkdirs()) {
                        throw new IOException("Could not create the updates folder.");
                    }
                    if (apk.exists() && !apk.delete()) {
                        throw new IOException("Could not replace the old download.");
                    }

                    downloadFile(apkUrl, apk, progress);

                    if (sha256 != null && !sha256.isEmpty()
                            && !sha256.equalsIgnoreCase(sha256(apk))) {
                        //noinspection ResultOfMethodCallIgnored
                        apk.delete();
                        throw new IOException("Checksum mismatch.");
                    }

                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            dismissQuietly(progress);
                            if (!isFinishing()) {
                                installApk(apk);
                            }
                        }
                    });
                } catch (final Exception e) {
                    //noinspection ResultOfMethodCallIgnored
                    apk.delete();
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            dismissQuietly(progress);
                            finishWithMessage("The update couldn't be downloaded. Please try again.");
                        }
                    });
                }
            }
        }).start();
    }

    private void downloadFile(String urlString, File target, final ProgressDialog progress)
            throws IOException {
        HttpURLConnection connection = null;
        InputStream input = null;
        FileOutputStream output = null;
        try {
            URL url = new URL(urlString);
            if (!"https".equalsIgnoreCase(url.getProtocol())) {
                throw new IOException("Updates must use HTTPS.");
            }

            connection = (HttpURLConnection) url.openConnection();
            connection.setConnectTimeout(15000);
            connection.setReadTimeout(30000);
            connection.setInstanceFollowRedirects(true);
            connection.connect();

            if (!"https".equalsIgnoreCase(connection.getURL().getProtocol())) {
                throw new IOException("Updates must use HTTPS.");
            }

            int status = connection.getResponseCode();
            if (status < 200 || status >= 300) {
                throw new IOException("HTTP " + status);
            }

            final int total = connection.getContentLength();
            runOnUiThread(new Runnable() {
                @Override
                public void run() {
                    progress.setIndeterminate(total <= 0);
                    if (total > 0) {
                        progress.setMax(total);
                        progress.setProgress(0);
                    }
                }
            });

            input = new BufferedInputStream(connection.getInputStream());
            output = new FileOutputStream(target);
            byte[] buffer = new byte[8192];
            long done = 0;
            int read;
            while ((read = input.read(buffer)) != -1) {
                output.write(buffer, 0, read);
                done += read;
                if (total > 0) {
                    final int percent = (int) (done * 100 / total);
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            progress.setProgress(percent);
                        }
                    });
                }
            }
            output.flush();
        } finally {
            if (output != null) {
                try {
                    output.close();
                } catch (IOException ignored) {
                }
            }
            if (input != null) {
                try {
                    input.close();
                } catch (IOException ignored) {
                }
            }
            if (connection != null) {
                connection.disconnect();
            }
        }
    }

    private void installApk(File apk) {
        Intent intent = new Intent(Intent.ACTION_VIEW);
        Uri uri;
        if (Build.VERSION.SDK_INT >= 24) {
            uri = FileProvider.getUriForFile(this, getPackageName() + ".fileprovider", apk);
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
        } else {
            // The installer predates content:// support on old devices;
            // file:// is still allowed below API 24.
            uri = Uri.fromFile(apk);
        }
        intent.setDataAndType(uri, "application/vnd.android.package-archive");
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);

        try {
            startActivity(intent);
            finish();
        } catch (ActivityNotFoundException e) {
            finishWithMessage("Couldn't open the installer on this device.");
        }
    }

    private JSONObject fetchJson(String urlString) throws Exception {
        HttpURLConnection connection = null;
        InputStream input = null;
        try {
            URL url = new URL(urlString);
            if (!"https".equalsIgnoreCase(url.getProtocol())) {
                throw new IOException("Updates must use HTTPS.");
            }

            connection = (HttpURLConnection) url.openConnection();
            connection.setConnectTimeout(15000);
            connection.setReadTimeout(15000);
            connection.setInstanceFollowRedirects(true);
            connection.setRequestProperty("Cache-Control", "no-cache");

            int status = connection.getResponseCode();
            if (status < 200 || status >= 300) {
                throw new IOException("HTTP " + status);
            }

            input = new BufferedInputStream(connection.getInputStream());
            ByteArrayOutputStream body = new ByteArrayOutputStream();
            byte[] buffer = new byte[4096];
            int read;
            while ((read = input.read(buffer)) != -1) {
                body.write(buffer, 0, read);
            }
            return new JSONObject(body.toString("UTF-8"));
        } finally {
            if (input != null) {
                try {
                    input.close();
                } catch (IOException ignored) {
                }
            }
            if (connection != null) {
                connection.disconnect();
            }
        }
    }

    private static String sha256(File file) throws Exception {
        MessageDigest digest = MessageDigest.getInstance("SHA-256");
        InputStream input = new BufferedInputStream(new FileInputStream(file));
        try {
            byte[] buffer = new byte[8192];
            int read;
            while ((read = input.read(buffer)) != -1) {
                digest.update(buffer, 0, read);
            }
        } finally {
            input.close();
        }

        StringBuilder hex = new StringBuilder();
        for (byte value : digest.digest()) {
            hex.append(String.format("%02x", value));
        }
        return hex.toString();
    }

    private void dismissQuietly(ProgressDialog dialog) {
        if (dialog != null && dialog.isShowing()) {
            try {
                dialog.dismiss();
            } catch (IllegalArgumentException ignored) {
            }
        }
    }

    private void finishWithMessage(String message) {
        if (isFinishing()) {
            return;
        }
        Toast.makeText(this, message, Toast.LENGTH_LONG).show();
        finish();
    }
}
