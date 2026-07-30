<?php
/**
 * SalesDesk — Outreach Programme helpers.
 *
 * Backs:
 *   - outreach/index.php               (public registration page)
 *   - api/outreach/register.php        (registration POST endpoint)
 *   - api/outreach/partner-enquiry.php (dealer/employer POST endpoint)
 *   - app/admin/outreach.php           (admin pipeline management)
 *
 * v2: the SA ID number field and its AES/HMAC encryption have been
 * removed entirely — this file no longer requires includes/encryption.php.
 * Duplicate-registration detection now uses the mobile number, backed
 * by a plain UNIQUE index (see db/0004_outreach_remove_id_number.sql).
 *
 * Require order: this file needs database.php and functions.php
 * (generateUuidV4, writeAuditLog) already loaded.
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

// ============================================================
// WHITELISTS — kept in one place so the API endpoint and the
// admin screen can both validate against the same source of truth.
// ============================================================

const OUTREACH_PROVINCES = [
    'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo',
    'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape',
];

const OUTREACH_QUALIFICATIONS = [
    'below_grade_12'      => 'Below Grade 12',
    'grade_12'             => 'Grade 12 / Matric',
    'certificate_diploma'  => 'Certificate / Diploma',
    'degree'               => 'Degree',
    'other'                => 'Other',
];

const OUTREACH_EMPLOYMENT_STATUSES = [
    'unemployed' => 'Unemployed',
    'part_time'  => 'Part-time employed',
    'full_time'  => 'Full-time employed',
    'studying'   => 'Studying',
];

const OUTREACH_LICENCE_CODES = ['8', '10', '14'];

const OUTREACH_STATUSES = ['new', 'shortlisted', 'contacted', 'enrolled', 'rejected'];

const OUTREACH_PARTNER_TYPES = ['dealer', 'employer'];


// ============================================================
// REGISTRATION — public "Register your interest" form
// ============================================================

/**
 * Validate + persist an outreach registration.
 *
 * @param array $input Raw, trimmed request data (already $_POST-shaped).
 * @return array{
 *   ok: bool,
 *   duplicate: bool,
 *   errors: array<string,string>,
 *   uuid: ?string
 * }
 */
function registerOutreachInterest(array $input): array
{
    $errors = [];

    $fullName      = trim((string) ($input['full_name'] ?? ''));
    $dob           = trim((string) ($input['date_of_birth'] ?? ''));
    $mobile        = preg_replace('/\D/', '', (string) ($input['mobile'] ?? ''));
    $email         = trim((string) ($input['email'] ?? '')) ?: null;
    $province      = trim((string) ($input['province'] ?? ''));
    $municipality  = trim((string) ($input['municipality'] ?? ''));
    $qualification = trim((string) ($input['qualification'] ?? ''));
    $employment    = trim((string) ($input['employment_status'] ?? ''));
    $hasLearners   = !empty($input['has_learners']) && $input['has_learners'] === 'yes';
    $hasDrivers    = !empty($input['has_drivers']) && $input['has_drivers'] === 'yes';
    $licenceCode   = trim((string) ($input['licence_code'] ?? ''));
    $motivation    = trim((string) ($input['motivation'] ?? ''));
    $consent       = !empty($input['consent_given']);

    if ($fullName === '' || mb_strlen($fullName) > 120) {
        $errors['full_name'] = 'Please enter your full name.';
    }
    if ($dob === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $errors['date_of_birth'] = 'Please enter a valid date of birth.';
    }
    if (!preg_match('/^0\d{9}$/', $mobile)) {
        $errors['mobile'] = 'Please enter a valid South African mobile number.';
    }
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if (!in_array($province, OUTREACH_PROVINCES, true)) {
        $errors['province'] = 'Please select a valid province.';
    }
    if ($municipality === '' || mb_strlen($municipality) > 80) {
        $errors['municipality'] = 'Please enter your municipality.';
    }
    if (!array_key_exists($qualification, OUTREACH_QUALIFICATIONS)) {
        $errors['qualification'] = 'Please select your highest qualification.';
    }
    if (!array_key_exists($employment, OUTREACH_EMPLOYMENT_STATUSES)) {
        $errors['employment_status'] = 'Please select your employment status.';
    }
    if (!in_array($licenceCode, OUTREACH_LICENCE_CODES, true)) {
        $errors['licence_code'] = 'Please select the licence code you are interested in.';
    }
    if ($motivation === '' || mb_strlen($motivation) > 2000) {
        $errors['motivation'] = 'Please share a short motivation (up to 2000 characters).';
    }
    if (!$consent) {
        $errors['consent_given'] = 'Please confirm you consent to us processing your details.';
    }

    if (!empty($errors)) {
        return ['ok' => false, 'duplicate' => false, 'errors' => $errors, 'uuid' => null];
    }

    $pdo = Database::getInstance();

    // Application-level duplicate check (fast path — friendlier message
    // than waiting for the DB unique-key exception below).
    $dupStmt = $pdo->prepare('SELECT id FROM outreach_registrations WHERE mobile = ? LIMIT 1');
    $dupStmt->execute([$mobile]);
    if ($dupStmt->fetch()) {
        return ['ok' => true, 'duplicate' => true, 'errors' => [], 'uuid' => null];
    }

    $uuid = generateUuidV4();
    $ip   = $_SERVER['REMOTE_ADDR'] ?? null;

    try {
        $pdo->prepare("
            INSERT INTO outreach_registrations (
                uuid, full_name, date_of_birth, mobile, email, province, municipality,
                qualification, employment_status, has_learners_licence, has_drivers_licence,
                licence_code, motivation, consent_given, consent_at,
                status, source, ip_address, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, 1, NOW(),
                'new', 'outreach_page', ?, NOW(), NOW()
            )
        ")->execute([
            $uuid, $fullName, $dob, $mobile, $email, $province, $municipality,
            $qualification, $employment, $hasLearners ? 1 : 0, $hasDrivers ? 1 : 0,
            $licenceCode, $motivation,
            $ip,
        ]);
    } catch (PDOException $e) {
        // Race condition: two near-simultaneous submissions with the
        // same mobile number both pass the SELECT check above. The
        // unique key on mobile is the real guarantee; treat this as
        // a duplicate rather than surfacing a 500.
        if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'uq_outreach_mobile')) {
            return ['ok' => true, 'duplicate' => true, 'errors' => [], 'uuid' => null];
        }
        error_log('[SalesDesk outreach] registerOutreachInterest insert failed: ' . $e->getMessage());
        return ['ok' => false, 'duplicate' => false, 'errors' => ['_global' => 'Something went wrong. Please try again.'], 'uuid' => null];
    }

    return ['ok' => true, 'duplicate' => false, 'errors' => [], 'uuid' => $uuid];
}


// ============================================================
// PARTNER ENQUIRIES — "I'm a dealership" / "I'm an employer"
// ============================================================

function submitOutreachPartnerEnquiry(array $input): array
{
    $errors = [];

    $type        = trim((string) ($input['enquiry_type'] ?? ''));
    $contactName = trim((string) ($input['contact_name'] ?? ''));
    $companyName = trim((string) ($input['company_name'] ?? '')) ?: null;
    $email       = trim((string) ($input['email'] ?? ''));
    $phone       = trim((string) ($input['phone'] ?? '')) ?: null;
    $message     = trim((string) ($input['message'] ?? '')) ?: null;

    if (!in_array($type, OUTREACH_PARTNER_TYPES, true)) {
        $errors['enquiry_type'] = 'Please specify whether this is a dealer or employer enquiry.';
    }
    if ($contactName === '' || mb_strlen($contactName) > 120) {
        $errors['contact_name'] = 'Please enter a contact name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors, 'uuid' => null];
    }

    $pdo  = Database::getInstance();
    $uuid = generateUuidV4();

    $pdo->prepare("
        INSERT INTO outreach_partner_enquiries
            (uuid, enquiry_type, contact_name, company_name, email, phone, message, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'new', NOW(), NOW())
    ")->execute([$uuid, $type, $contactName, $companyName, $email, $phone, $message]);

    return ['ok' => true, 'errors' => [], 'uuid' => $uuid];
}


// ============================================================
// ADMIN QUERIES
// ============================================================

function getOutreachStatsSummary(): array
{
    $pdo = Database::getInstance();
    $row = $pdo->query("
        SELECT
            COUNT(*)                                        AS total,
            SUM(status = 'new')         AS new_count,
            SUM(status = 'shortlisted') AS shortlisted_count,
            SUM(status = 'contacted')   AS contacted_count,
            SUM(status = 'enrolled')    AS enrolled_count,
            SUM(status = 'rejected')    AS rejected_count
        FROM outreach_registrations
    ")->fetch();

    return [
        'total'       => (int) ($row['total'] ?? 0),
        'new'         => (int) ($row['new_count'] ?? 0),
        'shortlisted' => (int) ($row['shortlisted_count'] ?? 0),
        'contacted'   => (int) ($row['contacted_count'] ?? 0),
        'enrolled'    => (int) ($row['enrolled_count'] ?? 0),
        'rejected'    => (int) ($row['rejected_count'] ?? 0),
    ];
}

/**
 * Live count used for the public "X people already on the waiting
 * list" social-proof line on /outreach/.
 */
function getOutreachRegistrationCount(): int
{
    $pdo = Database::getInstance();
    return (int) $pdo->query('SELECT COUNT(*) FROM outreach_registrations')->fetchColumn();
}

function getOutreachRegistrations(array $filters, int $limit = 25, int $offset = 0): array
{
    $pdo    = Database::getInstance();
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['status']) && in_array($filters['status'], OUTREACH_STATUSES, true)) {
        $where[]  = 'status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['province']) && in_array($filters['province'], OUTREACH_PROVINCES, true)) {
        $where[]  = 'province = ?';
        $params[] = $filters['province'];
    }
    if (!empty($filters['search'])) {
        $where[]  = '(full_name LIKE ? OR mobile LIKE ?)';
        $like     = '%' . $filters['search'] . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $whereClause = implode(' AND ', $where);
    $params[]    = $limit;
    $params[]    = $offset;

    $stmt = $pdo->prepare("
        SELECT id, uuid, full_name, date_of_birth, mobile, email,
               province, municipality, qualification, employment_status,
               has_learners_licence, has_drivers_licence, licence_code,
               motivation, status, admin_notes, created_at, status_updated_at
        FROM outreach_registrations
        WHERE {$whereClause}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countOutreachRegistrations(array $filters): int
{
    $pdo    = Database::getInstance();
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['status']) && in_array($filters['status'], OUTREACH_STATUSES, true)) {
        $where[]  = 'status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['province']) && in_array($filters['province'], OUTREACH_PROVINCES, true)) {
        $where[]  = 'province = ?';
        $params[] = $filters['province'];
    }
    if (!empty($filters['search'])) {
        $where[]  = '(full_name LIKE ? OR mobile LIKE ?)';
        $like     = '%' . $filters['search'] . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $whereClause = implode(' AND ', $where);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM outreach_registrations WHERE {$whereClause}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function getOutreachRegistrationById(int $id): array|false
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare('SELECT * FROM outreach_registrations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function updateOutreachRegistrationStatus(int $id, string $status, int $adminId, ?string $notes = null): bool
{
    if (!in_array($status, OUTREACH_STATUSES, true)) {
        return false;
    }

    $pdo = Database::getInstance();

    $current = $pdo->prepare('SELECT status, admin_notes FROM outreach_registrations WHERE id = ?');
    $current->execute([$id]);
    $row = $current->fetch();
    if (!$row) {
        return false;
    }

    $pdo->prepare("
        UPDATE outreach_registrations
        SET status = ?, admin_notes = ?, status_updated_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ")->execute([$status, $notes, $id]);

    writeAuditLog(
        'outreach.status_changed',
        'outreach_registration',
        $id,
        ['status' => $row['status']],
        ['status' => $status],
        $adminId
    );

    return true;
}

function getOutreachPartnerEnquiries(int $limit = 50, int $offset = 0): array
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT id, uuid, enquiry_type, contact_name, company_name, email, phone, message, status, created_at
        FROM outreach_partner_enquiries
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    return $stmt->fetchAll();
}
