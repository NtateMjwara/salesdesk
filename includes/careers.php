<?php
/**
 * SalesDesk — Careers helpers.
 *
 * Used by:
 *   careers/index.php          — public job listing
 *   careers/apply/index.php    — public application form
 *   app/admin/careers.php      — admin: postings + applications lists
 *   app/admin/careers-edit.php — admin: create/edit a posting
 *
 * Depends on functions.php (generateUuidV4, writeAuditLog) and
 * database.php (Database::getInstance). Require both before this file.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

// ============================================================
// LOOKUPS / LABELS
// ============================================================

const JOB_EMPLOYMENT_TYPES = [
    'full_time'  => 'Full-time',
    'part_time'  => 'Part-time',
    'contract'   => 'Contract',
    'internship' => 'Internship',
];

const JOB_WORK_MODES = [
    'remote'  => 'Remote',
    'hybrid'  => 'Hybrid',
    'on_site' => 'On-site',
];

const JOB_APPLICATION_STATUSES = [
    'new'           => 'New',
    'reviewing'     => 'Reviewing',
    'interviewing'  => 'Interviewing',
    'offered'       => 'Offered',
    'hired'         => 'Hired',
    'rejected'      => 'Rejected',
];

function jobEmploymentTypeLabel(string $type): string
{
    return JOB_EMPLOYMENT_TYPES[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

function jobWorkModeLabel(string $mode): string
{
    return JOB_WORK_MODES[$mode] ?? ucfirst(str_replace('_', ' ', $mode));
}

function jobApplicationStatusLabel(string $status): string
{
    return JOB_APPLICATION_STATUSES[$status] ?? ucfirst($status);
}


// ============================================================
// SLUGS
// ============================================================

function generateJobSlug(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return mb_substr($slug ?: 'role', 0, 150);
}

/**
 * Generate a slug guaranteed unique in job_postings, appending -2, -3…
 * if needed. $excludeId lets edits keep their own slug.
 */
function uniqueJobSlug(string $title, ?int $excludeId = null): string
{
    $pdo  = Database::getInstance();
    $base = generateJobSlug($title);
    $slug = $base;
    $i    = 2;

    while (true) {
        $sql    = 'SELECT id FROM job_postings WHERE slug = ?' . ($excludeId ? ' AND id != ?' : '') . ' LIMIT 1';
        $stmt   = $pdo->prepare($sql);
        $stmt->execute($excludeId ? [$slug, $excludeId] : [$slug]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}


// ============================================================
// JOB POSTINGS — reads
// ============================================================

/**
 * All published postings, newest first. Used by the public /careers/ page.
 * Wrapped by callers in try/catch so a missing table (pre-migration)
 * degrades gracefully rather than fataling the page.
 */
function getPublishedJobPostings(): array
{
    $pdo = Database::getInstance();
    return $pdo->query("
        SELECT id, uuid, title, slug, department, location,
               employment_type, work_mode, blurb, posted_at
        FROM job_postings
        WHERE status = 'published'
        ORDER BY posted_at DESC, created_at DESC
    ")->fetchAll();
}

function getJobPostingBySlug(string $slug, bool $publishedOnly = true): array|false
{
    $pdo  = Database::getInstance();
    $sql  = 'SELECT * FROM job_postings WHERE slug = ?';
    if ($publishedOnly) {
        $sql .= " AND status = 'published'";
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$slug]);
    return $stmt->fetch();
}

function getJobPostingById(int $id): array|false
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare('SELECT * FROM job_postings WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Admin listing — all statuses, with application counts, optional filter.
 */
function getJobPostingsForAdmin(?string $status = null): array
{
    $pdo    = Database::getInstance();
    $where  = '1=1';
    $params = [];
    if ($status) {
        $where    = 'jp.status = ?';
        $params[] = $status;
    }
    $stmt = $pdo->prepare("
        SELECT
            jp.*,
            (SELECT COUNT(*) FROM job_applications ja WHERE ja.job_posting_id = jp.id) AS application_count,
            (SELECT COUNT(*) FROM job_applications ja WHERE ja.job_posting_id = jp.id AND ja.status = 'new') AS new_application_count
        FROM job_postings jp
        WHERE {$where}
        ORDER BY jp.status = 'published' DESC, jp.created_at DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}


// ============================================================
// JOB POSTINGS — writes
// ============================================================

function createJobPosting(array $data, int $adminId): int
{
    $pdo  = Database::getInstance();
    $uuid = generateUuidV4();
    $slug = !empty($data['slug']) ? generateJobSlug($data['slug']) : uniqueJobSlug($data['title']);
    // Ensure uniqueness even if a slug was supplied explicitly.
    $slug = uniqueJobSlug($slug);

    $status   = in_array($data['status'] ?? '', ['draft', 'published', 'closed'], true) ? $data['status'] : 'draft';
    $postedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;

    $pdo->prepare("
        INSERT INTO job_postings
            (uuid, title, slug, department, location, employment_type, work_mode,
             blurb, description, status, posted_at, created_by, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
    ")->execute([
        $uuid,
        $data['title'],
        $slug,
        $data['department'],
        $data['location'],
        $data['employment_type'],
        $data['work_mode'],
        $data['blurb'],
        $data['description'] ?? null,
        $status,
        $postedAt,
        $adminId,
    ]);

    $id = (int) $pdo->lastInsertId();
    writeAuditLog('careers.posting_created', 'job_posting', $id, null, [
        'title' => $data['title'], 'status' => $status,
    ], $adminId);

    return $id;
}

function updateJobPosting(int $id, array $data, int $adminId): bool
{
    $pdo     = Database::getInstance();
    $current = getJobPostingById($id);
    if (!$current) {
        return false;
    }

    $slug = !empty($data['slug']) ? uniqueJobSlug(generateJobSlug($data['slug']), $id) : $current['slug'];

    $status   = in_array($data['status'] ?? '', ['draft', 'published', 'closed'], true) ? $data['status'] : $current['status'];
    $postedAt = $current['posted_at'];
    $closedAt = $current['closed_at'];

    if ($status === 'published' && $current['status'] !== 'published') {
        $postedAt = date('Y-m-d H:i:s');
    }
    if ($status === 'closed' && $current['status'] !== 'closed') {
        $closedAt = date('Y-m-d H:i:s');
    }
    if ($status !== 'closed') {
        $closedAt = null;
    }

    $pdo->prepare("
        UPDATE job_postings SET
            title = ?, slug = ?, department = ?, location = ?,
            employment_type = ?, work_mode = ?, blurb = ?, description = ?,
            status = ?, posted_at = ?, closed_at = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([
        $data['title'],
        $slug,
        $data['department'],
        $data['location'],
        $data['employment_type'],
        $data['work_mode'],
        $data['blurb'],
        $data['description'] ?? null,
        $status,
        $postedAt,
        $closedAt,
        $id,
    ]);

    writeAuditLog('careers.posting_updated', 'job_posting', $id,
        ['status' => $current['status']], ['status' => $status], $adminId);

    return true;
}

function setJobPostingStatus(int $id, string $status, int $adminId): bool
{
    if (!in_array($status, ['draft', 'published', 'closed'], true)) {
        return false;
    }
    $pdo     = Database::getInstance();
    $current = getJobPostingById($id);
    if (!$current) {
        return false;
    }

    $postedAt = $current['posted_at'];
    $closedAt = $current['closed_at'];
    if ($status === 'published' && $current['status'] !== 'published') {
        $postedAt = date('Y-m-d H:i:s');
    }
    $closedAt = $status === 'closed' ? date('Y-m-d H:i:s') : null;

    $pdo->prepare("
        UPDATE job_postings
        SET status = ?, posted_at = ?, closed_at = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$status, $postedAt, $closedAt, $id]);

    writeAuditLog('careers.posting_status_changed', 'job_posting', $id,
        ['status' => $current['status']], ['status' => $status], $adminId);

    return true;
}

function deleteJobPosting(int $id, int $adminId): bool
{
    $pdo     = Database::getInstance();
    $current = getJobPostingById($id);
    if (!$current) {
        return false;
    }
    // Applications keep their own record; job_posting_id just goes NULL (FK ON DELETE SET NULL).
    $pdo->prepare('DELETE FROM job_postings WHERE id = ?')->execute([$id]);
    writeAuditLog('careers.posting_deleted', 'job_posting', $id,
        ['title' => $current['title']], null, $adminId);
    return true;
}


// ============================================================
// APPLICATIONS — reads
// ============================================================

function getApplicationsForAdmin(?int $jobId = null, ?string $status = null, ?string $search = null, int $limit = 50, int $offset = 0): array
{
    $pdo    = Database::getInstance();
    $where  = ['1=1'];
    $params = [];

    if ($jobId) {
        $where[]  = 'ja.job_posting_id = ?';
        $params[] = $jobId;
    }
    if ($status) {
        $where[]  = 'ja.status = ?';
        $params[] = $status;
    }
    if ($search) {
        $where[]  = '(ja.full_name LIKE ? OR ja.email LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    $whereClause = implode(' AND ', $where);
    $params[]    = $limit;
    $params[]    = $offset;

    $stmt = $pdo->prepare("
        SELECT ja.*, jp.title AS job_title, jp.slug AS job_slug
        FROM job_applications ja
        LEFT JOIN job_postings jp ON jp.id = ja.job_posting_id
        WHERE {$whereClause}
        ORDER BY ja.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countApplicationsForAdmin(?int $jobId = null, ?string $status = null, ?string $search = null): int
{
    $pdo    = Database::getInstance();
    $where  = ['1=1'];
    $params = [];

    if ($jobId) {
        $where[]  = 'job_posting_id = ?';
        $params[] = $jobId;
    }
    if ($status) {
        $where[]  = 'status = ?';
        $params[] = $status;
    }
    if ($search) {
        $where[]  = '(full_name LIKE ? OR email LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    $whereClause = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_applications WHERE {$whereClause}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function getApplicationById(int $id): array|false
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT ja.*, jp.title AS job_title, jp.slug AS job_slug
        FROM job_applications ja
        LEFT JOIN job_postings jp ON jp.id = ja.job_posting_id
        WHERE ja.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    return $stmt->fetch();
}


// ============================================================
// APPLICATIONS — writes
// ============================================================

/**
 * @param array $data Keys: job_posting_id (?int), full_name, email, phone,
 *                     linkedin_url, portfolio_url, cover_note, resume_url,
 *                     resume_original_name, ip_address
 * @return int New application id.
 */
function createJobApplication(array $data): int
{
    $pdo  = Database::getInstance();
    $uuid = generateUuidV4();

    $pdo->prepare("
        INSERT INTO job_applications
            (uuid, job_posting_id, full_name, email, phone, linkedin_url,
             portfolio_url, cover_note, resume_url, resume_original_name,
             status, ip_address, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,'new',?,NOW(),NOW())
    ")->execute([
        $uuid,
        $data['job_posting_id'] ?: null,
        $data['full_name'],
        $data['email'],
        $data['phone'] ?: null,
        $data['linkedin_url'] ?: null,
        $data['portfolio_url'] ?: null,
        $data['cover_note'] ?: null,
        $data['resume_url'],
        $data['resume_original_name'] ?? null,
        $data['ip_address'] ?? null,
    ]);

    $id = (int) $pdo->lastInsertId();
    writeAuditLog('careers.application_received', 'job_application', $id, null, [
        'email'          => $data['email'],
        'job_posting_id' => $data['job_posting_id'] ?: null,
    ]);

    return $id;
}

function updateApplicationStatus(int $id, string $status, int $adminId, ?string $notes = null): bool
{
    if (!array_key_exists($status, JOB_APPLICATION_STATUSES)) {
        return false;
    }
    $pdo     = Database::getInstance();
    $current = getApplicationById($id);
    if (!$current) {
        return false;
    }

    $pdo->prepare("
        UPDATE job_applications
        SET status = ?, admin_notes = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$status, $notes !== null ? $notes : $current['admin_notes'], $id]);

    writeAuditLog('careers.application_status_changed', 'job_application', $id,
        ['status' => $current['status']], ['status' => $status], $adminId);

    return true;
}

function deleteApplication(int $id, int $adminId): bool
{
    $pdo  = Database::getInstance();
    $app  = getApplicationById($id);
    if (!$app) {
        return false;
    }
    // Best-effort remove the stored resume file.
    if (!empty($app['resume_url'])) {
        $path = __DIR__ . '/../' . ltrim($app['resume_url'], '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }
    $pdo->prepare('DELETE FROM job_applications WHERE id = ?')->execute([$id]);
    writeAuditLog('careers.application_deleted', 'job_application', $id,
        ['email' => $app['email']], null, $adminId);
    return true;
}


// ============================================================
// RESUME UPLOAD
// ============================================================

const RESUME_ALLOWED_EXT  = ['pdf', 'doc', 'docx'];
const RESUME_MAX_BYTES    = 5 * 1024 * 1024; // 5MB

/**
 * Validate + move an uploaded resume into /uploads/resumes/.
 * Filename on disk is a random token — never the user-supplied name —
 * to avoid path traversal / overwrite / XSS-via-filename issues.
 *
 * @param array $file One entry from $_FILES (e.g. $_FILES['resume'])
 * @return array{ok:bool, error?:string, url?:string, original_name?:string}
 */
function uploadResumeFile(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please attach your CV / resume.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed — please try again.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ($file['size'] > RESUME_MAX_BYTES) {
        return ['ok' => false, 'error' => 'File is too large — please keep it under 5MB.'];
    }

    $originalName = $file['name'];
    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, RESUME_ALLOWED_EXT, true)) {
        return ['ok' => false, 'error' => 'Please upload a PDF, DOC, or DOCX file.'];
    }

    // MIME sniff as a second check — extension alone is easy to spoof.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip', // some docx uploads report as zip container
    ];
    if (!in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'error' => 'That file doesn\'t look like a valid PDF or Word document.'];
    }

    $dir = __DIR__ . '/../uploads/resumes';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $token    = bin2hex(random_bytes(16));
    $filename = $token . '.' . $ext;
    $dest     = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save the file — please try again.'];
    }

    return [
        'ok'           => true,
        'url'          => '/uploads/resumes/' . $filename,
        'original_name' => $originalName,
    ];
}