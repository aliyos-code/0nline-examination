<?php
/**
 * Small helpers shared by the bulk CSV import features (questions, students)
 * and the template downloads.
 */

/**
 * Read an uploaded CSV file into an array of rows.
 *
 * A single leading header row is skipped automatically when its first cell is
 * a known header name. Completely blank lines are ignored. Every cell is
 * trimmed. The caller validates the contents.
 *
 * @param  string      $field  The $_FILES key of the uploaded file.
 * @param  string|null &$error Populated with a human-readable reason on failure.
 * @return array|null  Array of rows (each an indexed array of strings), or null.
 */
function csv_read_upload($field, &$error = null)
{
    $error = null;

    if (empty($_FILES[$field]) || !isset($_FILES[$field]['error'])) {
        $error = 'No file was uploaded.';
        return null;
    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'The upload failed (error code ' . (int) $file['error'] . ').';
        return null;
    }
    if ($file['size'] > 1048576) { // 1 MB is plenty for these imports.
        $error = 'The file is larger than the 1 MB limit.';
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid upload.';
        return null;
    }

    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        $error = 'The uploaded file could not be read.';
        return null;
    }

    $knownHeaders = ['question_text', 'reg_number', 'reg_no', 'registration_number'];
    $rows  = [];
    $first = true;

    while (($data = fgetcsv($handle, 0, ',')) !== false) {
        if (count($data) === 1 && trim((string) $data[0]) === '') {
            continue; // blank line
        }
        if ($first) {
            $first = false;
            $probe = isset($data[0]) ? strtolower(trim((string) $data[0])) : '';
            if (in_array($probe, $knownHeaders, true)) {
                continue; // header row
            }
        }
        $rows[] = array_map(function ($value) { return trim((string) $value); }, $data);
    }

    fclose($handle);

    return $rows;
}

/**
 * Stream rows to the browser as a UTF-8 CSV download. Must be called before any
 * HTML output is produced.
 *
 * @param string $filename
 * @param array  $rows      Each element is an array of cells.
 */
function csv_send($filename, array $rows)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 correctly.
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
