<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom wp_evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.
use EvoMembers\Core\Database;
defined('ABSPATH') || exit;

final class LicenseTypeService {
    public function save(array $data): int {
        global $wpdb;
        $id=absint($data['id']??0);
        $code=sanitize_key((string)($data['code']??''));
        $name=sanitize_text_field((string)($data['name']??''));
        if(!$code||!$name){return 0;}
        $payload=[
            'code'=>$code,'name'=>$name,
            'verification_mode'=>$this->mode($data['verification_mode']??'portable'),
            'license_seats'=>max(1,absint($data['license_seats']??$data['max_activations']??1)),
            'max_activations'=>1,
            'duration_days'=>!empty($data['duration_days'])?absint($data['duration_days']):null,
            'status'=>sanitize_key((string)($data['status']??'active'))==='inactive'?'inactive':'active',
            'settings_json'=>wp_json_encode([]),
            'updated_at'=>current_time('mysql',true),
        ];
        if($id){
            return false===$wpdb->update(Database::table('license_types'),$payload,['id'=>$id])?0:$id;
        }
        $payload['created_at']=current_time('mysql',true);
        return false===$wpdb->insert(Database::table('license_types'),$payload)?0:(int)$wpdb->insert_id;
    }
    public function delete(int $id): bool {
        global $wpdb;
        if($id<1){return false;}
        return false!==$wpdb->delete(Database::table('license_types'),['id'=>$id],['%d']);
    }
    public function all(): array {
        global $wpdb;
        $r=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY name',Database::table('license_types')),ARRAY_A);
        return is_array($r)?$r:[];
    }
    public function get(int $id): ?array {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',Database::table('license_types'),$id),ARRAY_A);
        return is_array($r)?$r:null;
    }
    private function mode(mixed $v):string{
        $v=sanitize_key((string)$v);
        return in_array($v,['portable','site','domain','server_ip','domain_ip'],true)?$v:'portable';
    }
}
