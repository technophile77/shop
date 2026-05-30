<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Represents an admin user record in the `admin_users` table.
 *
 * Provides static methods for listing, finding, creating, and deleting admin
 * accounts. Passwords are stored as bcrypt hashes and are never returned in
 * plain text. All reads use the read-only PDO connection; all writes use the
 * read-write connection.
 *
 * @see \App\Core\Database
 */
final class AdminUser
{
    /**
     * Returns all admin users ordered by creation date ascending.
     *
     * Selects only the columns needed for display — the password_hash column
     * is intentionally excluded.
     *
     * @return array<int, array<string, mixed>> All admin_users rows (id, name,
     *         email, last_login, created_at).
     *
     * @example
     *   $admins = AdminUser::all();
     */
    public static function all(): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT id, name, email, last_login, created_at
             FROM admin_users
             ORDER BY created_at ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Finds a single admin user by primary key.
     *
     * @param int $id The admin user ID.
     *
     * @return array<string, mixed>|null The admin_users row, or null when not found.
     *
     * @example
     *   $admin = AdminUser::find(1);
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT id, name, email, last_login, created_at
             FROM admin_users
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Finds a single admin user by email address.
     *
     * Used to check for duplicate email addresses before creating a new admin.
     *
     * @param string $email The email address to search.
     *
     * @return array<string, mixed>|null The admin_users row, or null when not found.
     *
     * @example
     *   $existing = AdminUser::findByEmail('manager@example.com');
     */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT id, name, email, last_login, created_at
             FROM admin_users
             WHERE email = ?
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Creates a new admin user and returns the new row's ID.
     *
     * The password is hashed with bcrypt (cost 12) before insertion — the
     * plain-text value is never persisted.
     *
     * @param string $name     Full display name.
     * @param string $email    Unique email address.
     * @param string $password Plain-text password; minimum 8 characters is
     *                         enforced by the caller.
     *
     * @return int The auto-increment ID of the newly created row.
     *
     * @example
     *   $id = AdminUser::create('Jane Smith', 'jane@example.com', 'secret123');
     */
    public static function create(string $name, string $email, string $password): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = Database::rw()->prepare(
            'INSERT INTO admin_users (name, email, password_hash)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, $email, $hash]);

        return (int) Database::rw()->lastInsertId();
    }

    /**
     * Permanently deletes an admin user by primary key.
     *
     * The caller is responsible for preventing self-deletion before calling
     * this method.
     *
     * @param int $id The admin user ID to delete.
     *
     * @return void
     *
     * @example
     *   AdminUser::delete(3);
     */
    public static function delete(int $id): void
    {
        $stmt = Database::rw()->prepare(
            'DELETE FROM admin_users WHERE id = ?'
        );
        $stmt->execute([$id]);
    }
}
