<?php
/**
 * SalesDesk — Newsletter & Blog helpers.
 *
 * Requires mailer.php (for sendEmail + emailLayout).
 * Requires config.php (for SITE_URL).
 *
 * Functions:
 *
 *   SLUG
 *     generateBlogSlug(title)           — URL-safe slug from a title
 *     uniqueBlogSlug(title, excludeId?) — slug guaranteed unique in blog_posts
 *
 *   NEWSLETTER EMAIL
 *     sendNewsletterConfirmation(email, firstName, token)
 *     sendNewsletterWelcome(email, firstName)
 *     sendNewsletterBroadcast(campaign, subscriber)
 *
 *   SUBSCRIBER HELPERS
 *     subscribeEmail(email, firstName, source)   — insert / re-subscribe
 *     confirmSubscription(token)                 — activate pending subscriber
 *     unsubscribeByToken(token)                  — one-click unsubscribe
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/mailer.php';


// ============================================================
// SLUG HELPERS
// ============================================================

/**
 * Convert a title string to a URL-safe slug.
 * e.g. "Ford Ranger Super Duty — 2027" → "ford-ranger-super-duty-2027"
 */
function generateBlogSlug(string $title): string
{
    $slug = mb_strtolower($title, 'UTF-8');
    // Replace non-alphanumeric (keep hyphens) with a space
    $slug = preg_replace('/[^a-z0-9\s-]/u', '', $slug);
    // Collapse whitespace and hyphens
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return substr($slug, 0, 200);
}

/**
 * Return a slug that does not already exist in blog_posts.
 * If the base slug is taken, appends -2, -3, … until unique.
 *
 * @param string   $title      Source title.
 * @param int|null $excludeId  Exclude this post ID from the uniqueness check (for edits).
 */
function uniqueBlogSlug(string $title, ?int $excludeId = null): string
{
    $pdo  = Database::getInstance();
    $base = generateBlogSlug($title);
    $slug = $base;
    $n    = 1;

    while (true) {
        $stmt = $pdo->prepare(
            'SELECT id FROM blog_posts WHERE slug = ?' .
            ($excludeId ? ' AND id != ?' : '') .
            ' LIMIT 1'
        );
        $params = $excludeId ? [$slug, $excludeId] : [$slug];
        $stmt->execute($params);

        if (!$stmt->fetch()) {
            break; // slug is free
        }

        $n++;
        $suffix = '-' . $n;
        $slug   = substr($base, 0, 200 - strlen($suffix)) . $suffix;
    }

    return $slug;
}


// ============================================================
// NEWSLETTER EMAIL FUNCTIONS
// ============================================================

/**
 * Send the double opt-in confirmation email.
 */
function sendNewsletterConfirmation(
    string $email,
    ?string $firstName,
    string $token
): bool {
    $confirmUrl = SITE_URL . '/api/newsletter/confirm.php?token=' . urlencode($token);
    $name       = $firstName ? htmlspecialchars($firstName) : 'there';

    $body = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">
  Confirm your subscription
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 8px;">
  Hi {$name},
</p>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 24px;">
  Thanks for signing up for SalesDesk car news &amp; deal alerts.
  Click below to confirm your email address and activate your subscription.
</p>
<a href="{$confirmUrl}"
   style="display:inline-block;background:#0f4c9e;color:#fff;
          font-size:15px;font-weight:600;padding:12px 26px;
          border-radius:8px;text-decoration:none;">
  Confirm subscription →
</a>
<p style="font-size:13px;color:#94a3b8;margin:20px 0 0;line-height:1.6;">
  This link expires in 48 hours.
  If you didn't subscribe, you can safely ignore this email — no account has been created.
</p>
HTML;

    return sendEmail($email, 'Confirm your SalesDesk newsletter subscription', $body);
}

/**
 * Send a welcome email once the subscriber confirms.
 */
function sendNewsletterWelcome(string $email, ?string $firstName): bool
{
    $browseUrl = SITE_URL . '/c/';
    $name      = $firstName ? htmlspecialchars($firstName) : 'there';

    $body = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#15803d;margin:0 0 8px;">
  You're subscribed ✓
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 8px;">
  Hi {$name},
</p>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 24px;">
  Welcome to the SalesDesk newsletter. You'll receive car news, new launches,
  market updates, and exclusive deal alerts straight to your inbox.
</p>
<a href="{$browseUrl}"
   style="display:inline-block;background:#0f4c9e;color:#fff;
          font-size:15px;font-weight:600;padding:12px 26px;
          border-radius:8px;text-decoration:none;">
  Browse vehicles →
</a>
HTML;

    return sendEmail($email, 'Welcome to the SalesDesk newsletter', $body);
}

/**
 * Send a newsletter campaign to a single subscriber.
 * Called in a loop from the admin newsletter-compose send action.
 *
 * The campaign's HTML content is wrapped in emailLayout() via sendEmail(),
 * then an unsubscribe footer is appended before the layout wrapper.
 */
function sendNewsletterBroadcast(array $campaign, array $subscriber): bool
{
    $unsubUrl = SITE_URL . '/newsletter/unsubscribe/?token='
              . urlencode($subscriber['unsubscribe_token']);

    $unsubBlock = <<<HTML
<div style="margin-top:28px;padding-top:20px;border-top:1px solid #e2e8f0;text-align:center;">
  <p style="font-size:11px;color:#94a3b8;margin:0;line-height:1.8;">
    You're receiving this because you subscribed to SalesDesk newsletters.<br>
    <a href="{$unsubUrl}" style="color:#64748b;text-decoration:underline;">Unsubscribe</a>
    &nbsp;&middot;&nbsp; South Africa
  </p>
</div>
HTML;

    $body = $campaign['content'] . $unsubBlock;

    return sendEmail(
        $subscriber['email'],
        $campaign['subject'],
        $body
    );
}


// ============================================================
// SUBSCRIBER MANAGEMENT
// ============================================================

/**
 * Insert a new subscriber or re-subscribe an existing unsubscribed one.
 *
 * Returns an array:
 *   ['ok' => bool, 'state' => 'new'|'pending'|'already_active'|'resubscribed']
 */
function subscribeEmail(
    string $email,
    ?string $firstName = null,
    string $source = 'footer'
): array {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare('SELECT id, status FROM newsletter_subscribers WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($existing['status'] === 'active') {
            return ['ok' => true, 'state' => 'already_active'];
        }
        if ($existing['status'] === 'pending') {
            return ['ok' => true, 'state' => 'pending'];
        }
        // unsubscribed / bounced → re-subscribe with fresh tokens
        $confirmToken   = bin2hex(random_bytes(32));
        $unsubscribeToken = bin2hex(random_bytes(32));
        $pdo->prepare("
            UPDATE newsletter_subscribers
            SET status = 'pending',
                first_name = ?,
                confirm_token = ?,
                unsubscribe_token = ?,
                confirmed_at = NULL,
                source = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$firstName, $confirmToken, $unsubscribeToken, $source, $existing['id']]);

        return [
            'ok'    => true,
            'state' => 'resubscribed',
            'token' => $confirmToken,
        ];
    }

    // Brand-new subscriber
    $confirmToken     = bin2hex(random_bytes(32));
    $unsubscribeToken = bin2hex(random_bytes(32));

    $pdo->prepare("
        INSERT INTO newsletter_subscribers
            (email, first_name, status, confirm_token, unsubscribe_token, source, subscribed_at)
        VALUES (?, ?, 'pending', ?, ?, ?, NOW())
    ")->execute([$email, $firstName, $confirmToken, $unsubscribeToken, $source]);

    return [
        'ok'    => true,
        'state' => 'new',
        'token' => $confirmToken,
    ];
}

/**
 * Confirm a pending subscription by token.
 *
 * @return array  ['ok'=>bool, 'subscriber'=>array|null, 'reason'=>string]
 */
function confirmSubscription(string $token): array
{
    if (!$token) {
        return ['ok' => false, 'reason' => 'invalid_token'];
    }

    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT id, email, first_name, status, subscribed_at
        FROM newsletter_subscribers
        WHERE confirm_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $sub = $stmt->fetch();

    if (!$sub) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    if ($sub['status'] === 'active') {
        return ['ok' => true, 'reason' => 'already_confirmed', 'subscriber' => $sub];
    }
    // Token expires 48 hours after subscription
    $expiry = strtotime($sub['subscribed_at']) + 172800;
    if (time() > $expiry) {
        return ['ok' => false, 'reason' => 'expired'];
    }

    $pdo->prepare("
        UPDATE newsletter_subscribers
        SET status = 'active',
            confirm_token = NULL,
            confirmed_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$sub['id']]);

    return ['ok' => true, 'reason' => 'confirmed', 'subscriber' => $sub];
}

/**
 * Unsubscribe by token (one-click, no login required).
 *
 * @return array  ['ok'=>bool, 'subscriber'=>array|null]
 */
function unsubscribeByToken(string $token): array
{
    if (!$token) {
        return ['ok' => false];
    }

    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT id, email, first_name FROM newsletter_subscribers
        WHERE unsubscribe_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $sub = $stmt->fetch();

    if (!$sub) {
        return ['ok' => false];
    }

    $pdo->prepare("
        UPDATE newsletter_subscribers
        SET status = 'unsubscribed', updated_at = NOW()
        WHERE id = ?
    ")->execute([$sub['id']]);

    return ['ok' => true, 'subscriber' => $sub];
}
