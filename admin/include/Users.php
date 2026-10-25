<?php
declare(strict_types=1);

class Users extends DB_Object
{
    protected static $db_table = "tblusers";
    protected static $db_table_fields = array(
        'id',
        'firstname',
        'lastname',
        'gender',
        'username',
        'password',
        'email',
        'designation',
        'address',
        'access_level',
        'profile_picture',
        'date_created'
    );

    public $id;
    public $firstname;
    public $lastname;
    public $gender;
    public $username;
    public $password;
    public $email;
    public $designation;
    public $access_level;
    public $address;
    public $profile_picture;
    public $date_created;

    public $tmp_path;
    public $type;
    public $size;

    public $upload_directory = "upload/users";
    /*
     * A LOCAL placeholder, not http://placehold.it/64x64&text=images. That host
     * has been dead for years and the URL was plain http, so every staff member
     * without a photo rendered a broken image and a mixed-content warning on an
     * HTTPS install. This file ships with the app.
     */
    public $image_placeholder = "upload/users/user-icn-p-min.png";
    public $errors = array();

    public $upload_errors_array = array(
        UPLOAD_ERR_OK         => "There is no error",
        UPLOAD_ERR_INI_SIZE   => "The uploaded file exceeds the upload_max_filesize disc",
        UPLOAD_ERR_FORM_SIZE  => "The uploaded file exceeds the MAX_FILE_SIZE directives",
        UPLOAD_ERR_PARTIAL    => "The uploaded file was only partially uploaded.",
        UPLOAD_ERR_NO_FILE    => "No file was uploaded",
        UPLOAD_ERR_NO_TMP_DIR => "Missing a temporary folder",
        UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk",
        UPLOAD_ERR_EXTENSION  => "A PHP extension stopped the file upload."
    );

    public function set_file($file)
    {
        if (empty($file) || !$file || !is_array($file)) {
            $this->errors[] = "There was no file uploaded here";
            return false;
        } elseif ($file['error'] != 0) {
            $this->errors[] = $this->upload_errors_array[$file['error']];
            return false;
        } else {
            // Image-only guard: extension allowlist + getimagesize sniff + size
            // cap, stored under a randomized name (matches Gallery/EventWedding).
            $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                        'gif' => 'image/gif', 'webp' => 'image/webp'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $maxBytes = 5 * 1024 * 1024;
            if (!isset($allowed[$ext]) || empty($file['size']) || $file['size'] > $maxBytes
                || @getimagesize($file['tmp_name']) === false) {
                $this->errors[] = 'Only JPG/PNG/GIF/WebP images up to 5 MB are allowed.';
                return false;
            }
            $this->profile_picture = bin2hex(random_bytes(8)) . '.' . $ext;
            $this->tmp_path = $file['tmp_name'];
            $this->type = $file['type'];
            $this->size = $file['size'];
        }
    }

    public function profile_picture_picture() {
        return empty($this->profile_picture) ? $this->image_placeholder : $this->upload_directory . '/' . $this->profile_picture;
    }

    public function picture_path() {
        return $this->upload_directory . '/' . $this->profile_picture;
    }

    public function delete_photo() {
        if ($this->delete()) {
            if (empty($this->profile_picture)) {
                return true; // nothing on disk to remove
            }
            $target_path = SITE_ROOT . DS . $this->upload_directory . DS . $this->profile_picture;
            if (file_exists($target_path)) {
                return @unlink($target_path) ? true : false;
            }
            return true; // DB row removed; file already absent
        } else {
            return false;
        }
    }

    public function save_image()
    {
        if (!empty($this->errors)) {
            return false;
        }
        if (empty($this->profile_picture) || empty($this->tmp_path)) {
            $this->errors[] = "The file was not available";
            return false;
        }
        $target_path = SITE_ROOT . DS . $this->upload_directory . DS . $this->profile_picture;
        if (file_exists($target_path)) {
            $this->errors[] = "The file {$this->profile_picture} already exists";
            return false;
        }
        if (move_uploaded_file($this->tmp_path, $target_path)) {
            unset($this->tmp_path);
            return true;
        } else {
            $this->errors[] = "The File directory probably does not have permession";
            return false;
        }
    }

    public function save(): bool {
        if ($this->id) {
            return (bool) $this->update();
        } else {
            if (!empty($this->errors)) {
                return false;
            }
            /*
             * The avatar is OPTIONAL. The Add/Edit Staff and profile forms do
             * not require one, but a missing upload made set_file() record
             * "There was no file uploaded here", which the guard above then
             * turned into a refused INSERT — and the page reported success
             * anyway, so the operator was told an account existed that was
             * never written. A real upload still moves its file as before; a
             * genuinely absent one creates the account with an empty
             * profile_picture (the column is NOT NULL, so '' not null).
             *
             * A file that FAILED validation still reaches the guard above and
             * is refused here, so a rejected avatar can never be silently
             * dropped and the account created without it.
             */
            if (empty($this->profile_picture) || empty($this->tmp_path)) {
                $this->profile_picture = (string) ($this->profile_picture ?? '');
                return $this->create();
            }
            $target_path = SITE_ROOT . '/' . $this->upload_directory . '/' . $this->profile_picture;
            if (file_exists($target_path)) {
                $this->errors[] = "The file {$this->profile_picture} already exists";
                return false;
            }
            if (move_uploaded_file($this->tmp_path, $target_path)) {
                if ($this->create()) {
                    unset($this->tmp_path);
                    return true;
                }
                return false;
            } else {
                $this->errors[] = "The File directory probably does not have permession";
                return false;
            }
        }
        return false;
    }

    /**
     * Login via PDO prepared statement — bcrypt verified.
     */
    public static function user_account_login(string $email, string $password): object|false
    {
        global $db;
        $sql  = "SELECT * FROM " . self::$db_table . " WHERE email = :email LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_OBJ);

        if ($user && Session::verify_password($password, $user->password)) {
            return $user;
        }
        return false;
    }

    /**
     * Check email existence — PDO prepared (was SQL-injected via string interpolation).
     * (Column fix: tblusers has email/id — the old user_email/user_id names
     * belong to tblaccounts, so this always threw or missed.)
     */
    public static function email_exists(string $email): bool
    {
        global $db;
        $sql  = "SELECT id FROM " . self::$db_table . " WHERE email = :email LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':email' => $email]);
        return $stmt->fetch() !== false;
    }
}
