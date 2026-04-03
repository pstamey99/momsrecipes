<?php
/**
 * mailer.php - Mom's Recipes Email Helper
 * Uses PHPMailer with Gmail SMTP
 * PHPMailer files must be at: api/vendor/phpmailer/src/
 */

require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function sendEmail($toEmail, $toName, $subject, $htmlBody, &$errorMsg = null) {
    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('mailer: invalid email: ' . $toEmail);
        $errorMsg = 'Invalid email address: ' . $toEmail;
        return false;
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = mailerWrap($subject, $htmlBody);
        $mail->AltBody = strip_tags($htmlBody);
        $mail->send();
        error_log('mailer: sent [' . $subject . '] to ' . $toEmail);
        return true;
    } catch (Exception $e) {
        $errorMsg = $mail->ErrorInfo;
        error_log('mailer: FAILED [' . $subject . '] to ' . $toEmail . ' -- ' . $mail->ErrorInfo);
        return false;
    }
}

function mailerWrap($title, $body) {
    $siteUrl  = SITE_URL;
    $fromName = FROM_NAME;
    $html  = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    $html .= '<title>' . htmlspecialchars($title) . '</title>';
    $html .= '<style>';
    $html .= 'body{margin:0;padding:0;background:#f5f0e8;font-family:Georgia,serif;}';
    $html .= '.w{max-width:560px;margin:32px auto;background:#fff;border-radius:8px;border:1px solid #d4c5a9;overflow:hidden;}';
    $html .= '.h{background:#8b4513;padding:24px 32px;text-align:center;}';
    $html .= '.h h1{margin:0;color:#fff;font-size:22px;}';
    $html .= '.h p{margin:4px 0 0;color:#f5deb3;font-size:13px;}';
    $html .= '.b{padding:28px 32px;color:#3d2b1f;line-height:1.65;}';
    $html .= '.b h2{color:#8b4513;font-size:18px;margin-top:0;}';
    $html .= '.btn{display:inline-block;margin:8px 0 16px;padding:10px 24px;background:#8b4513;color:#fff;text-decoration:none;border-radius:4px;font-size:14px;}';
    $html .= '.f{background:#f5f0e8;padding:16px 32px;text-align:center;font-size:12px;color:#999;border-top:1px solid #d4c5a9;}';
    $html .= '.f a{color:#8b4513;}';
    $html .= '</style></head><body>';
    $html .= '<div class="w">';
    $html .= '<div class="h"><h1>' . htmlspecialchars($fromName) . '</h1>';
    $html .= '<p>Preserving family recipes, one dish at a time</p></div>';
    $html .= '<div class="b">' . $body . '</div>';
    $html .= '<div class="f"><p>You\'re receiving this because you have notifications enabled.<br>';
    $html .= '<a href="' . $siteUrl . '">Visit Mom\'s Recipes</a></p></div>';
    $html .= '</div></body></html>';
    return $html;
}

function notifyNewRecipe($recipe, $subscribers) {
    $title       = htmlspecialchars($recipe['title'] ?? 'Untitled');
    $contributor = htmlspecialchars($recipe['contributor'] ?? 'Someone');
    $source      = htmlspecialchars($recipe['family_source'] ?? '');
    $uuid        = $recipe['uuid'] ?? '';
    $url         = SITE_URL . '/recipes/' . $uuid . '.html';
    $fromLine    = $source ? ' (originally from ' . $source . ')' : '';
    $body  = '<h2>New recipe added!</h2>';
    $body .= '<p><strong>' . $contributor . '</strong> just added a new recipe' . $fromLine . ':</p>';
    $body .= '<p style="font-size:20px;color:#8b4513;"><strong>' . $title . '</strong></p>';
    $body .= '<p><a class="btn" href="' . $url . '">View Recipe</a></p>';
    foreach ($subscribers as $user) {
        sendEmail($user['email'], $user['username'], 'New recipe: ' . $title, $body);
    }
}

function notifyReaction($reaction, $subscribers) {
    $emoji       = htmlspecialchars($reaction['emoji'] ?? 'a reaction');
    $reactor     = htmlspecialchars($reaction['reactor'] ?? 'Someone');
    $recipeTitle = htmlspecialchars($reaction['recipe_title'] ?? 'a recipe');
    $uuid        = $reaction['recipe_uuid'] ?? '';
    $url         = SITE_URL . '/recipes/' . $uuid . '.html';
    $body  = '<h2>Someone reacted to a recipe!</h2>';
    $body .= '<p><strong>' . $reactor . '</strong> reacted ' . $emoji . ' to:</p>';
    $body .= '<p style="font-size:18px;color:#8b4513;"><strong>' . $recipeTitle . '</strong></p>';
    $body .= '<p><a class="btn" href="' . $url . '">View Recipe</a></p>';
    foreach ($subscribers as $user) {
        sendEmail($user['email'], $user['username'], $reactor . ' reacted to "' . $recipeTitle . '"', $body);
    }
}

function notifyRecipeEdited($edit, $subscribers) {
    $recipeTitle = htmlspecialchars($edit['recipe_title'] ?? 'a recipe');
    $editor      = htmlspecialchars($edit['editor'] ?? 'Someone');
    $uuid        = $edit['recipe_uuid'] ?? '';
    $url         = SITE_URL . '/recipes/' . $uuid . '.html';
    $body  = '<h2>Recipe updated</h2>';
    $body .= '<p><strong>' . $editor . '</strong> made changes to:</p>';
    $body .= '<p style="font-size:18px;color:#8b4513;"><strong>' . $recipeTitle . '</strong></p>';
    $body .= '<p><a class="btn" href="' . $url . '">View Updated Recipe</a></p>';
    foreach ($subscribers as $user) {
        sendEmail($user['email'], $user['username'], 'Recipe updated: "' . $recipeTitle . '"', $body);
    }
}

function sendWeeklyDigest($digest, $subscribers) {
    $weekLabel  = htmlspecialchars($digest['week_label'] ?? date('F j'));
    $newRecipes = $digest['new_recipes']  ?? [];
    $topReacted = $digest['top_reactions'] ?? [];

    $recipeRows = '';
    foreach ($newRecipes as $r) {
        $t   = htmlspecialchars($r['title']);
        $c   = htmlspecialchars($r['contributor'] ?? '');
        $url = SITE_URL . '/recipes/' . ($r['uuid'] ?? '') . '.html';
        $recipeRows .= '<p><a href="' . $url . '" style="color:#8b4513;">' . $t . '</a>';
        if ($c) $recipeRows .= ' <em style="color:#999;">by ' . $c . '</em>';
        $recipeRows .= '</p>';
    }
    if (!$recipeRows) $recipeRows = '<p><em>No new recipes this week.</em></p>';

    $reactionRows = '';
    foreach ($topReacted as $r) {
        $t   = htmlspecialchars($r['title']);
        $url = SITE_URL . '/recipes/' . ($r['uuid'] ?? '') . '.html';
        $reactionRows .= '<p><a href="' . $url . '" style="color:#8b4513;">' . $t . '</a></p>';
    }
    if (!$reactionRows) $reactionRows = '<p><em>Nothing yet this week.</em></p>';

    $body  = '<h2>Weekly Digest - ' . $weekLabel . '</h2>';
    $body .= '<p>Here\'s what happened in the family recipe collection this week.</p>';
    $body .= '<h3 style="color:#8b4513;font-size:16px;margin-bottom:6px;">New Recipes Added</h3>';
    $body .= $recipeRows;
    $body .= '<h3 style="color:#8b4513;font-size:16px;margin-bottom:6px;">Most Loved This Week</h3>';
    $body .= $reactionRows;
    $body .= '<p style="margin-top:20px;"><a class="btn" href="' . SITE_URL . '">Browse All Recipes</a></p>';

    foreach ($subscribers as $user) {
        sendEmail($user['email'], $user['username'], "Mom's Recipes - Week of " . $weekLabel, $body);
    }
}
