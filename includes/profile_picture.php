<?php
/**
 * Secure profile-picture upload handling (server-side only).
 *
 * Validates image type/size, stores with a random filename under
 * assets/uploads/, and never trusts the client-supplied filename.
 * Works on PHP 7.3+.
 */

require_once __DIR__ . '/functions.php';

define('PROFILE_PIC_MAX_BYTES', 2 * 1024 * 1024); // 2 MB
define('PROFILE_PIC_ALLOWED_MIMES', 'image/jpeg,image/png,image/gif,image/webp');

function profile_pic_upload_dir($role)
{
    $role = ($role === 'admin') ? 'admins' : 'students';
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads';
    $dir = $base . DIRECTORY_SEPARATOR . $role;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        // Block script execution inside the uploads folder.
        if (!is_file($base . DIRECTORY_SEPARATOR . '.htaccess')) {
            file_put_contents($base . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|asp|aspx|shtml)$\">\n  Require all denied\n</FilesMatch>\nphp_flag engine off\n");
        }
        if (!is_file($dir . DIRECTORY_SEPARATOR . 'index.html')) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '');
        }
    }
    return $dir;
}

function profile_pic_url($role, $filename)
{
    if (empty($filename)) {
        return null;
    }
    $role = ($role === 'admin') ? 'admins' : 'students';
    return url('assets/uploads/' . $role . '/' . rawurlencode(basename($filename)));
}

function profile_pic_initials($name)
{
    $parts = preg_split('/\s+/', trim((string) $name));
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') {
            $initials .= strtoupper(substr($p, 0, 1));
        }
        if (strlen($initials) >= 2) {
            break;
        }
    }
    return $initials !== '' ? $initials : '?';
}

/**
 * Handle an uploaded profile picture.
 * @param string $role 'admin'|'student'
 * @param array  $file $_FILES entry (field 'profile_picture')
 * @param string|null $oldFilename Current stored filename to delete on success
 * @return array ['ok'=>bool,'filename'=>string|null,'error'=>string|null]
 */
function handle_profile_picture_upload($role, $file, $oldFilename = null)
{
    if (!isset($file) || !is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return array('ok' => false, 'filename' => $oldFilename, 'error' => 'No file was uploaded.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msgs = array(
            UPLOAD_ERR_INI_SIZE => 'The picture is too large.',
            UPLOAD_ERR_FORM_SIZE => 'The picture is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload was incomplete. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server error: upload folder missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server error: cannot write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'The upload was stopped by a server extension.',
        );
        return array('ok' => false, 'filename' => $oldFilename, 'error' => isset($msgs[$file['error']]) ? $msgs[$file['error']] : 'Upload failed. Please try again.');
    }
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return array('ok' => false, 'filename' => $oldFilename, 'error' => 'Invalid upload.');
    }
    if (isset($file['size']) && $file['size'] > PROFILE_PIC_MAX_BYTES) {
        return array('ok' => false, 'filename' => $oldFilename, 'error' => 'Picture must be at most 2 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return array('ok' => false, 'filename' => $oldFilename, 'error' => 'Only image files (JPG, PNG, GIF, WebP) are allowed.');
    }
    $mime = isset($info['mime']) ? $info['mime'] : '';
    $allowed = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp');
    if (!isset($allowed[$mime])) {
        return array('ok' => false, 'filename' => $oldFilename, 'error' => 'Only image files (JPG, PNG, GIF, WebP) are allowed.');
    }
    $ext = $allowed[$mime];
    try {
        $random = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        $random = sha1(uniqid('', true));
    }
    $filename = $random . '.' . $ext;
    $dir = profile_pic_upload_dir($role);
    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return array('ok' => false, 'filename' => $oldFilename, 'error' => 'Could not save the picture. Please try again.');
    }
    @chmod($dest, 0644);
    if (!empty($oldFilename)) {
        $oldPath = $dir . DIRECTORY_SEPARATOR . basename($oldFilename);
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }
    return array('ok' => true, 'filename' => $filename, 'error' => null);
}

function delete_profile_picture($role, $filename)
{
    if (empty($filename)) {
        return;
    }
    $path = profile_pic_upload_dir($role) . DIRECTORY_SEPARATOR . basename($filename);
    if (is_file($path)) {
        @unlink($path);
    }
}
