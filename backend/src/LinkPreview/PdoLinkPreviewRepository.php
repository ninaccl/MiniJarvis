<?php

namespace App\LinkPreview;

use PDO;
final class PdoLinkPreviewRepository implements LinkPreviewRepository
{
    private $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function create(array $preview)
    {
        $statement = $this->pdo->prepare('INSERT INTO jarvis_link_previews (household_id, user_id, recipe_link_id, token_hash, url_hash, platform, normalized_url, title, description, image_url, temp_image_path, image_mime_type, site_name, fetched_at, expires_at) ' . 'VALUES (:household_id, :user_id, NULL, :token_hash, :url_hash, :platform, :normalized_url, :title, NULL, :image_url, :temp_image_path, :image_mime_type, NULL, :fetched_at, :expires_at)');
        $statement->execute($preview);
        return (int) $this->pdo->lastInsertId();
    }
    public function findForAdoption($tokenHash, $householdId, $userId)
    {
        $statement = $this->pdo->prepare('SELECT id, household_id, user_id, token_hash, temp_image_path, image_mime_type, expires_at, adopted_at FROM jarvis_link_previews WHERE token_hash = :token_hash AND household_id = :household_id AND user_id = :user_id FOR UPDATE');
        $statement->execute(['token_hash' => $tokenHash, 'household_id' => $householdId, 'user_id' => $userId]);
        $row = $statement->fetch();
        return $row === false ? null : ['id' => (int) $row['id'], 'household_id' => (int) $row['household_id'], 'user_id' => (int) $row['user_id'], 'token_hash' => (string) $row['token_hash'], 'temp_image_path' => $row['temp_image_path'] === null ? null : (string) $row['temp_image_path'], 'image_mime_type' => $row['image_mime_type'] === null ? null : (string) $row['image_mime_type'], 'expires_at' => (string) $row['expires_at'], 'adopted_at' => $row['adopted_at'] === null ? null : (string) $row['adopted_at']];
    }
    public function findForImage($tokenHash, $householdId, $userId)
    {
        $statement = $this->pdo->prepare('SELECT id, household_id, user_id, token_hash, temp_image_path, image_mime_type, expires_at, adopted_at FROM jarvis_link_previews WHERE token_hash = :token_hash AND household_id = :household_id AND user_id = :user_id');
        $statement->execute(['token_hash' => $tokenHash, 'household_id' => $householdId, 'user_id' => $userId]);
        $row = $statement->fetch();
        return $row === false ? null : ['id' => (int) $row['id'], 'household_id' => (int) $row['household_id'], 'user_id' => (int) $row['user_id'], 'token_hash' => (string) $row['token_hash'], 'temp_image_path' => $row['temp_image_path'] === null ? null : (string) $row['temp_image_path'], 'image_mime_type' => $row['image_mime_type'] === null ? null : (string) $row['image_mime_type'], 'expires_at' => (string) $row['expires_at'], 'adopted_at' => $row['adopted_at'] === null ? null : (string) $row['adopted_at']];
    }
    public function markAdopted($id, $adoptedAt, $publicUrl)
    {
        $statement = $this->pdo->prepare('UPDATE jarvis_link_previews SET adopted_at = :adopted_at, image_url = :url WHERE id = :id AND adopted_at IS NULL');
        $statement->execute(['adopted_at' => $adoptedAt, 'url' => $publicUrl, 'id' => $id]);
        return $statement->rowCount() === 1;
    }
    public function clearTemporaryPath($id)
    {
        $statement = $this->pdo->prepare('UPDATE jarvis_link_previews SET temp_image_path = NULL WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
    /** @return list<array{id:int,temp_image_path:?string,adopted:bool}> */
    public function temporaryFilesForCleanup($before, $limit = 500)
    {
        $statement = $this->pdo->prepare('SELECT id, temp_image_path, adopted_at FROM jarvis_link_previews WHERE (adopted_at IS NOT NULL AND temp_image_path IS NOT NULL) OR (adopted_at IS NULL AND expires_at <= :before) ORDER BY id LIMIT :limit');
        $statement->bindValue(':before', $before);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map(static function (array $row) {
            return ['id' => (int) $row['id'], 'temp_image_path' => $row['temp_image_path'] === null ? null : (string) $row['temp_image_path'], 'adopted' => $row['adopted_at'] !== null];
        }, $statement->fetchAll());
    }
    public function deleteExpired($id, $before)
    {
        $statement = $this->pdo->prepare('DELETE FROM jarvis_link_previews WHERE id = :id AND adopted_at IS NULL AND expires_at <= :before');
        $statement->execute(['id' => $id, 'before' => $before]);
        return $statement->rowCount() === 1;
    }
}
