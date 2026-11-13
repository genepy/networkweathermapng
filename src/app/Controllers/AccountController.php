<?php
/**
 * NetworkWeathermapNG - Account Controller
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\KeyboardShortcuts;

class AccountController extends BaseController
{
    public function changePasswordForm(array $params): void
    {
        $this->requireAuth();

        $this->render('account/change_password', [
            'title' => __('Change password'),
        ]);
    }

    public function changePassword(array $params): void
    {
        $this->requireAuth();

        $currentPassword = (string) $this->getInput('current_password', '');
        $newPassword = (string) $this->getInput('new_password', '');
        $confirmPassword = (string) $this->getInput('confirm_password', '');

        if ($newPassword === '' || $confirmPassword === '') {
            $this->flash('error', __('New password and confirmation are required'));
            $this->redirect('/account/password');
            return;
        }

        if ($newPassword !== $confirmPassword) {
            $this->flash('error', __('New password and confirmation do not match'));
            $this->redirect('/account/password');
            return;
        }

        if (strlen($newPassword) < 8) {
            $this->flash('error', __('New password must be at least 8 characters'));
            $this->redirect('/account/password');
            return;
        }

        $user = $this->auth->user();
        $userId = (int) ($user['id'] ?? 0);

        $dbUser = $this->database->queryOne(
            'SELECT id, password_hash FROM users WHERE id = ? AND active = 1',
            [$userId]
        );

        if (!$dbUser) {
            $this->flash('error', __('User not found'));
            $this->redirect('/account/password');
            return;
        }

        if (!password_verify($currentPassword, $dbUser['password_hash'])) {
            $this->flash('error', __('Current password is incorrect'));
            $this->redirect('/account/password');
            return;
        }

        $this->database->update('users', [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ], 'id = ?', [$userId]);

        $this->auth->passwordChanged();
        $this->flash('success', __('Password changed successfully'));
        $this->redirect('/account/password');
    }

    /** The keyboard shortcuts of the signed-in user (UI: assets/js/shortcuts-page.js) */
    public function shortcuts(array $params): void
    {
        $this->requireAuth();
        $userId = (int) ($this->auth->user()['id'] ?? 0);
        $actions = [];
        $labels = KeyboardShortcuts::labels();
        foreach (KeyboardShortcuts::ACTIONS as $id => [$area, $defaults]) {
            $actions[] = [
                'id' => $id,
                'area' => $area,
                'label' => $labels[$id],
                'defaults' => $defaults,
                'choices' => KeyboardShortcuts::DRAG_KEYS[$id] ?? null,
            ];
        }
        $areas = [];
        foreach (KeyboardShortcuts::areaLabels() as $id => $label) {
            $areas[] = ['id' => $id, 'label' => $label];
        }

        $this->render('account/shortcuts', [
            'title' => __('Keyboard shortcuts'),
            'canSave' => $userId > 0,
            'fixed' => KeyboardShortcuts::fixed(),
            'areas' => KeyboardShortcuts::areaLabels(),
            'page' => [
                'areas' => $areas,
                'actions' => $actions,
                'bindings' => (new KeyboardShortcuts($this->database))->forUser($userId),
                'reserved' => KeyboardShortcuts::RESERVED,
                'areaReserved' => KeyboardShortcuts::AREA_RESERVED,
                'max' => KeyboardShortcuts::MAX_KEYS,
                'canSave' => $userId > 0,
            ],
        ]);
    }

    /** Save the keys of the signed-in user: JSON {bindings: {id: [keys]}} */
    public function saveShortcuts(array $params): void
    {
        $this->requireAuth();
        $userId = (int) ($this->auth->user()['id'] ?? 0);
        if ($userId <= 0) {
            $this->json(['ok' => false, 'error' => __('Sign in to keep your own keyboard shortcuts.')], 403);
            return;
        }
        $bindings = $this->getJsonInput()['bindings'] ?? null;
        if (!is_array($bindings)) {
            $this->json(['ok' => false, 'error' => __('Invalid request')], 400);
            return;
        }
        $service = new KeyboardShortcuts($this->database);
        $problems = $service->save($userId, $bindings);
        if ($problems) {
            $this->json(['ok' => false, 'error' => __('Some keys cannot be saved.'), 'problems' => $problems], 422);
            return;
        }
        $this->json(['ok' => true, 'bindings' => $service->forUser($userId)]);
    }
}
