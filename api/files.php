<?php
use UDrive\Auth\Auth;
use UDrive\Database\Database;
use UDrive\Engine\FileManager;
use UDrive\Encryption\CryptoEngine;
use UDrive\Encryption\StreamCrypto;

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$body = array_merge($_GET, $body);

switch ($action) {
    case 'list':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);
        $parentId = isset($body['folder']) ? (int) $body['folder'] : null;
        $files = $fm->listFiles($parentId);
        echo json_encode(['ok' => true, 'files' => $files]);
        break;

    case 'starred':
        $user = Auth::requireAuth();
        $files = Database::fetchAll(
            "SELECT f.*, d.name as drive_name, d.provider as drive_provider
             FROM files f JOIN drives d ON f.drive_id = d.id
             WHERE f.user_id = ? AND f.starred = 1 AND f.trashed = 0
             ORDER BY f.name ASC",
            [$user['id']]
        );
        echo json_encode(['ok' => true, 'files' => $files]);
        break;

    case 'search':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);
        $query = $body['q'] ?? $_GET['q'] ?? '';
        $files = $fm->searchFiles($query);
        echo json_encode(['ok' => true, 'files' => $files]);
        break;

    case 'recent':
        $user = Auth::requireAuth();
        $files = Database::fetchAll(
            "SELECT f.*, d.name as drive_name, d.provider as drive_provider
             FROM files f JOIN drives d ON f.drive_id = d.id
             WHERE f.user_id = ? AND f.trashed = 0 AND f.is_folder = 0
             ORDER BY f.updated_at DESC LIMIT 50",
            [$user['id']]
        );
        echo json_encode(['ok' => true, 'files' => $files]);
        break;

    case 'trashed':
        $user = Auth::requireAuth();
        $files = Database::fetchAll(
            "SELECT f.*, d.name as drive_name, d.provider as drive_provider
             FROM files f JOIN drives d ON f.drive_id = d.id
             WHERE f.user_id = ? AND f.trashed = 1
             ORDER BY f.updated_at DESC",
            [$user['id']]
        );
        echo json_encode(['ok' => true, 'files' => $files]);
        break;

    case 'upload':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);

        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded']);
            break;
        }

        $file = $_FILES['file'];
        $parentId = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : null;
        $driveId = isset($_POST['drive_id']) ? (int) $_POST['drive_id'] : null;
        $encrypted = isset($_POST['encrypted']) && $_POST['encrypted'] === '1';

        $encMeta = null;
        $data = file_get_contents($file['tmp_name']);

        if ($encrypted) {
            $key = Auth::getEncryptionKey($user['id']);
            if (!$key) {
                http_response_code(403);
                echo json_encode(['error' => 'Encryption key not unlocked. Please enter your password.']);
                break;
            }

            $crypto = new CryptoEngine($key);
            $tempEnc = tempnam(sys_get_temp_dir(), 'ud_enc_');
            $encMeta = $crypto->encryptFile($file['tmp_name'], $tempEnc);
            $data = file_get_contents($tempEnc);
            unlink($tempEnc);
            $encMeta['iv'] = $encMeta['iv'] ?? '';
            $encMeta['tag'] = $encMeta['tag'] ?? '';
        }

        try {
            $result = $fm->uploadFile(
                $file['name'],
                $file['type'] ?: 'application/octet-stream',
                $data,
                $parentId,
                $driveId,
                $encrypted,
                $encMeta
            );
            echo json_encode(['ok' => true, 'file' => $result]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'create_folder':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);
        $name = $body['name'] ?? '';
        $parentId = isset($body['folder_id']) ? (int) $body['folder_id'] : null;
        $driveId = isset($body['drive_id']) ? (int) $body['drive_id'] : null;

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['error' => 'Folder name required']);
            break;
        }

        try {
            $result = $fm->createFolder($name, $parentId, $driveId);
            echo json_encode(['ok' => true, 'folder' => $result]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'delete':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);
        $fileId = (int) ($body['id'] ?? 0);
        $permanent = isset($body['permanent']) && $body['permanent'] === true;

        if (!$fileId) {
            http_response_code(400);
            echo json_encode(['error' => 'File ID required']);
            break;
        }

        if ($fm->deleteFile($fileId, $permanent)) {
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
        }
        break;

    case 'rename':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);
        $fileId = (int) ($body['id'] ?? 0);
        $newName = $body['name'] ?? '';

        if (!$fileId || empty($newName)) {
            http_response_code(400);
            echo json_encode(['error' => 'File ID and name required']);
            break;
        }

        if ($fm->renameFile($fileId, $newName)) {
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
        }
        break;

    case 'move':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);
        $fileId = (int) ($body['id'] ?? 0);
        $targetDriveId = (int) ($body['target_drive_id'] ?? 0);
        $targetParentId = isset($body['target_parent_id']) ? (int) $body['target_parent_id'] : null;

        if (!$fileId || !$targetDriveId) {
            http_response_code(400);
            echo json_encode(['error' => 'File ID and target drive required']);
            break;
        }

        if ($fm->moveFile($fileId, $targetDriveId, $targetParentId)) {
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Move failed']);
        }
        break;

    case 'copy':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);
        $fileId = (int) ($body['id'] ?? 0);
        $targetDriveId = (int) ($body['target_drive_id'] ?? 0);
        $targetParentId = isset($body['target_parent_id']) ? (int) $body['target_parent_id'] : null;

        if (!$fileId || !$targetDriveId) {
            http_response_code(400);
            echo json_encode(['error' => 'File ID and target drive required']);
            break;
        }

        $newId = $fm->copyFile($fileId, $targetDriveId, $targetParentId);
        if ($newId) {
            echo json_encode(['ok' => true, 'new_id' => $newId]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Copy failed']);
        }
        break;

    case 'star':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);
        $fileId = (int) ($body['id'] ?? 0);
        if ($fileId) {
            $newState = $fm->toggleStar($fileId);
            echo json_encode(['ok' => true, 'starred' => $newState]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Failed']);
        }
        break;

    case 'restore':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);
        $fileId = (int) ($body['id'] ?? 0);
        if ($fileId && $fm->restoreFile($fileId)) {
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Failed']);
        }
        break;

    case 'transfer':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);
        $fileId = (int) ($body['file_id'] ?? 0);
        $targetDriveId = (int) ($body['target_drive_id'] ?? 0);
        $targetParentId = isset($body['target_parent_id']) ? (int) $body['target_parent_id'] : null;

        if (!$fileId || !$targetDriveId) {
            http_response_code(400);
            echo json_encode(['error' => 'File ID and target drive required']);
            break;
        }

        if ($fm->transferFile($fileId, $targetDriveId, $targetParentId)) {
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Transfer failed']);
        }
        break;

    case 'download':
        $user = Auth::requireAuth();
        $fm = new FileManager($user['id']);
        $fileId = (int) ($_GET['id'] ?? 0);

        if (!$fileId) {
            http_response_code(400);
            echo json_encode(['error' => 'File ID required']);
            break;
        }

        $file = $fm->getFile($fileId);
        if (!$file || $file['is_folder']) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            break;
        }

        $stream = $fm->downloadFile($fileId);
        if (!$stream) {
            http_response_code(500);
            echo json_encode(['error' => 'Download failed']);
            break;
        }

        if ($file['is_encrypted']) {
            $key = Auth::getEncryptionKey($user['id']);
            if (!$key) {
                fclose($stream);
                http_response_code(403);
                echo json_encode(['error' => 'Encryption key not unlocked']);
                break;
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'ud_dl_');
            $tempOut = fopen($tempPath, 'wb');
            stream_copy_to_stream($stream, $tempOut);
            fclose($stream);
            fclose($tempOut);

            $crypto = new CryptoEngine($key);
            $decPath = tempnam(sys_get_temp_dir(), 'ud_dec_');
            if ($crypto->decryptFile($tempPath, $decPath)) {
                header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
                header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
                header('Content-Length: ' . filesize($decPath));
                readfile($decPath);
                unlink($tempPath);
                unlink($decPath);
            } else {
                unlink($tempPath);
                unlink($decPath);
                http_response_code(500);
                echo json_encode(['error' => 'Decryption failed. Wrong password?']);
            }
        } else {
            $tempPath = tempnam(sys_get_temp_dir(), 'ud_dl_');
            $fp = fopen($tempPath, 'w');
            stream_copy_to_stream($stream, $fp);
            fclose($stream);
            fclose($fp);
            header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
            header('Content-Length: ' . filesize($tempPath));
            readfile($tempPath);
            unlink($tempPath);
        }
        exit;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
