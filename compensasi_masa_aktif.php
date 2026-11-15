<?php
/**
 * Plugin Kompensasi Masa Aktif untuk PHPNuxBill 2025.10.25
 * Hanya memperpanjang layanan PREPAID yang statusnya ON dan belum expired.
 */
register_menu('Kompensasi Masa Aktif', true, 'kompensasi_masa_aktif', 'SERVICES', 'ion ion-calendar');

function kompensasi_masa_aktif()
{
    global $ui, $config;
    _admin();
    $admin = Admin::_info();
    if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'], true)) {
        _alert(Lang::T('You do not have permission to access this page'), 'danger', 'dashboard');
        exit;
    }

    $db = ORM::get_db();
    $db->exec("CREATE TABLE IF NOT EXISTS tbl_compensation_batches (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        batch_code VARCHAR(40) NOT NULL,
        days INT NOT NULL,
        note VARCHAR(255) DEFAULT NULL,
        admin_id INT NOT NULL DEFAULT 0,
        admin_name VARCHAR(100) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        total_customers INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id), UNIQUE KEY uq_batch_code (batch_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS tbl_compensation_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        batch_id BIGINT UNSIGNED NOT NULL,
        customer_id INT NOT NULL,
        recharge_id INT NOT NULL,
        username VARCHAR(100) NOT NULL,
        old_expiration DATE NOT NULL,
        new_expiration DATE NOT NULL,
        expiry_time TIME DEFAULT NULL,
        days INT NOT NULL,
        sms_status VARCHAR(20) DEFAULT 'skipped',
        wa_status VARCHAR(20) DEFAULT 'skipped',
        email_status VARCHAR(20) DEFAULT 'skipped',
        inbox_status VARCHAR(20) DEFAULT 'skipped',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id), KEY idx_batch (batch_id), KEY idx_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $message = '';
    $error = '';
    $today = date('Y-m-d');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && _post('action') === 'apply') {
        if (!Csrf::check(_post('csrf_token'))) {
            $error = 'Token keamanan tidak valid atau sudah kedaluwarsa. Muat ulang halaman.';
        } else {
            $days = (int)_post('days');
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['recharge_ids'] ?? [])))));
            $note = trim((string)_post('note'));
            $notify = _post('notify') === '1';
            $channels = [
                'sms' => isset($_POST['sms']),
                'wa' => isset($_POST['wa']),
                'email' => isset($_POST['mail']),
                'inbox' => isset($_POST['inbox'])
            ];
            if ($days < 1 || $days > 365) $error = 'Jumlah hari harus antara 1 sampai 365.';
            elseif (!$ids) $error = 'Pilih minimal satu pelanggan.';
            elseif ($notify && !in_array(true, $channels, true)) $error = 'Pilih minimal satu kanal notifikasi atau matikan notifikasi.';
            else {
                $batchCode = 'KMP-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $db->beginTransaction();
                try {
                    $stmt = $db->prepare("INSERT INTO tbl_compensation_batches (batch_code,days,note,admin_id,admin_name,created_at,total_customers) VALUES (?,?,?,?,?,?,0)");
                    $stmt->execute([$batchCode,$days,$note,$admin['id'],$admin['fullname'],date('Y-m-d H:i:s')]);
                    $batchId = (int)$db->lastInsertId();
                    $changed = 0;
                    $select = $db->prepare("SELECT r.id recharge_id,r.customer_id,r.username,r.expiration,r.time,r.status,r.recharged_on,r.namebp,
                        c.fullname,c.phonenumber,c.email,p.name_plan,p.prepaid
                        FROM tbl_user_recharges r
                        INNER JOIN tbl_customers c ON c.id=r.customer_id
                        INNER JOIN tbl_plans p ON p.id=r.plan_id
                        WHERE r.id=? AND r.status='on' AND p.prepaid='yes' AND r.expiration>=CURDATE()
                        FOR UPDATE");
                    $update = $db->prepare("UPDATE tbl_user_recharges SET expiration=? WHERE id=? AND status='on'");
                    $insert = $db->prepare("INSERT INTO tbl_compensation_items (batch_id,customer_id,recharge_id,username,old_expiration,new_expiration,expiry_time,days,created_at) VALUES (?,?,?,?,?,?,?,?,?)");
                    foreach ($ids as $rid) {
                        $select->execute([$rid]);
                        $row = $select->fetch(PDO::FETCH_ASSOC);
                        if (!$row) continue;
                        $old = $row['expiration'];
                        $new = date('Y-m-d', strtotime($old . ' +' . $days . ' days'));
                        $update->execute([$new,$rid]);
                        if ($update->rowCount() < 1) continue;
                        $insert->execute([$batchId,$row['customer_id'],$rid,$row['username'],$old,$new,$row['time'],$days,date('Y-m-d H:i:s')]);
                        $itemId = (int)$db->lastInsertId();
                        $changed++;

                        if ($notify) {
                            $body = "Yth. {$row['fullname']},\n\nSebagai kompensasi atas gangguan layanan internet, masa aktif layanan Anda telah ditambahkan {$days} hari.\n\nUsername: {$row['username']}\nPaket: {$row['name_plan']}\nExpired sebelumnya: {$old} {$row['time']}\nExpired terbaru: {$new} {$row['time']}\n\nTerima kasih atas kepercayaan Anda.";
                            $subject = 'Informasi Kompensasi Masa Aktif';
                            $statuses = [];
                            if ($channels['sms']) { try { Message::sendSMS($row['phonenumber'],$body); $statuses['sms']='sent'; } catch (Throwable $e) { $statuses['sms']='failed'; _log('Kompensasi SMS gagal: '.$e->getMessage()); } }
                            if ($channels['wa']) { try { Message::sendWhatsapp($row['phonenumber'],$body); $statuses['wa']='sent'; } catch (Throwable $e) { $statuses['wa']='failed'; _log('Kompensasi WA gagal: '.$e->getMessage()); } }
                            if ($channels['email']) { try { $result=Message::sendEmail($row['email'],$subject,$body); $statuses['email']=$result ? 'sent' : 'failed'; } catch (Throwable $e) { $statuses['email']='failed'; _log('Kompensasi Email gagal: '.$e->getMessage()); } }
                            if ($channels['inbox']) { try { $result=Message::addToInbox($row['customer_id'],$subject,$body); $statuses['inbox']=$result ? 'sent' : 'failed'; } catch (Throwable $e) { $statuses['inbox']='failed'; _log('Kompensasi Inbox gagal: '.$e->getMessage()); } }
                            foreach ($statuses as $channel=>$status) {
                                $col = $channel === 'wa' ? 'wa_status' : ($channel === 'email' ? 'email_status' : ($channel === 'inbox' ? 'inbox_status' : 'sms_status'));
                                $up = $db->prepare("UPDATE tbl_compensation_items SET {$col}=? WHERE id=?"); $up->execute([$status,$itemId]);
                            }
                        }
                    }
                    $upBatch=$db->prepare("UPDATE tbl_compensation_batches SET total_customers=? WHERE id=?");
                    $upBatch->execute([$changed,$batchId]);
                    $db->commit();
                    $message = "Kompensasi selesai. {$changed} pelanggan berhasil diperpanjang {$days} hari. Kode batch: {$batchCode}.";
                    _log("{$admin['fullname']} menjalankan kompensasi {$days} hari untuk {$changed} pelanggan. Batch {$batchCode}",$admin['user_type'],$admin['id']);
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Proses kompensasi gagal dan perubahan database dibatalkan: ' . $e->getMessage();
                }
            }
        }
    }

    $status = trim((string)_req('status'));
    $expiryFilter = trim((string)_req('expiry'));
    $search = trim((string)_req('search'));
    $sql = "SELECT r.id recharge_id,r.customer_id,r.username,r.expiration,r.time,r.status,r.recharged_on,r.namebp,
        c.fullname,p.name_plan,p.prepaid
        FROM tbl_user_recharges r
        INNER JOIN tbl_customers c ON c.id=r.customer_id
        INNER JOIN tbl_plans p ON p.id=r.plan_id
        WHERE p.prepaid='yes' AND r.status='on' AND r.expiration>=CURDATE()";
    $params=[];
    if ($expiryFilter === 'today') $sql .= " AND r.expiration=CURDATE()";
    elseif ($expiryFilter === '7') $sql .= " AND r.expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    elseif ($expiryFilter === '30') $sql .= " AND r.expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    elseif ($expiryFilter === 'later') $sql .= " AND r.expiration>DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    if ($search !== '') { $sql .= " AND (c.fullname LIKE ? OR r.username LIKE ? OR p.name_plan LIKE ?)"; $like='%'.$search.'%'; $params=[$like,$like,$like]; }
    $sql .= " ORDER BY r.expiration ASC LIMIT 1000";
    $q=$db->prepare($sql); $q->execute($params); $customers=$q->fetchAll(PDO::FETCH_ASSOC);

    $history=$db->query("SELECT * FROM tbl_compensation_batches ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $ui->assign('_title','Kompensasi Masa Aktif');
    $ui->assign('_system_menu','compensasi_masa_aktif');
    $ui->assign('_admin',$admin);
    $ui->assign('customers',$customers);
    $ui->assign('history',$history);
    $ui->assign('csrf_token',Csrf::generateAndStoreToken());
    $ui->assign('message',$message);
    $ui->assign('error',$error);
    $ui->assign('search',$search);
    $ui->assign('expiryFilter',$expiryFilter);
    $ui->display('compensasi_masa_aktif.tpl');
}
