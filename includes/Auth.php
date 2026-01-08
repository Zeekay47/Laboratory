<?php
require_once 'Database.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function login($username, $password) {
        $this->db->query('SELECT * FROM staff WHERE username = :username AND is_active = 1');
        $this->db->bind(':username', $username);
        $user = $this->db->single();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            
            // Update last login
            $this->db->query('UPDATE staff SET last_login = NOW() WHERE id = :id');
            $this->db->bind(':id', $user['id']);
            $this->db->execute();
            
            return true;
        }
        return false;
    }
    
    public function logout() {
        session_destroy();
        header('Location: login.php');
        exit();
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function getUserRole() {
        return $_SESSION['role'] ?? null;
    }
    
    public function requireAuth() {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit();
        }
    }
    
    public function requireRole($role) {
        $this->requireAuth();
        if ($_SESSION['role'] !== $role) {
            header('Location: index.php');
            exit();
        }
    }
    
    public function canAccess($allowedRoles) {
        $this->requireAuth();
        if (!in_array($_SESSION['role'], haystack: $allowedRoles)) {
            header('Location: index.php');
            exit();
        }
    }
}
?>