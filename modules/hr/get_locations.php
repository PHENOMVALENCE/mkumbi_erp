<?php
define('APP_ACCESS', true);
session_start();

require_once '../../config/database.php';
require_once '../../config/auth.php';

$auth = new Auth();
$auth->requireLogin();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$csvFile = '../../assets/file/locations.csv';

// Check if CSV file exists
if (!file_exists($csvFile)) {
    echo json_encode(['success' => false, 'message' => 'Location data file not found']);
    exit;
}

// Read CSV file
$locationsData = [];
if (($handle = fopen($csvFile, 'r')) !== FALSE) {
    $headers = fgetcsv($handle); // Read header row
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 7) {
            $locationsData[] = [
                'region' => trim($row[0]),
                'regioncode' => trim($row[1]),
                'district' => trim($row[2]),
                'districtcode' => trim($row[3]),
                'ward' => trim($row[4]),
                'wardcode' => trim($row[5]),
                'street' => trim($row[6])
            ];
        }
    }
    fclose($handle);
}

switch ($action) {
    case 'get_regions':
        // Get unique regions
        $regions = [];
        $seen = [];
        
        foreach ($locationsData as $location) {
            if (!empty($location['region']) && !isset($seen[$location['region']])) {
                $regions[] = [
                    'name' => $location['region'],
                    'code' => $location['regioncode']
                ];
                $seen[$location['region']] = true;
            }
        }
        
        // Sort regions alphabetically
        usort($regions, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        echo json_encode(['success' => true, 'data' => $regions]);
        break;
        
    case 'get_districts':
        $region = $_GET['region'] ?? '';
        
        // Get districts for the selected region
        $districts = [];
        $seen = [];
        
        foreach ($locationsData as $location) {
            if ($location['region'] === $region && 
                !empty($location['district']) && 
                !isset($seen[$location['district']])) {
                $districts[] = [
                    'name' => $location['district'],
                    'code' => $location['districtcode']
                ];
                $seen[$location['district']] = true;
            }
        }
        
        // Sort districts alphabetically
        usort($districts, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        echo json_encode(['success' => true, 'data' => $districts]);
        break;
        
    case 'get_wards':
        $region = $_GET['region'] ?? '';
        $district = $_GET['district'] ?? '';
        
        // Get wards for the selected district
        $wards = [];
        $seen = [];
        
        foreach ($locationsData as $location) {
            if ($location['region'] === $region && 
                $location['district'] === $district && 
                !empty($location['ward']) && 
                !isset($seen[$location['ward']])) {
                $wards[] = [
                    'name' => $location['ward'],
                    'code' => $location['wardcode']
                ];
                $seen[$location['ward']] = true;
            }
        }
        
        // Sort wards alphabetically
        usort($wards, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        echo json_encode(['success' => true, 'data' => $wards]);
        break;
        
    case 'get_streets':
        $region = $_GET['region'] ?? '';
        $district = $_GET['district'] ?? '';
        $ward = $_GET['ward'] ?? '';
        
        // Get streets for the selected ward
        $streets = [];
        $seen = [];
        
        foreach ($locationsData as $location) {
            if ($location['region'] === $region && 
                $location['district'] === $district && 
                $location['ward'] === $ward && 
                !empty($location['street']) && 
                !isset($seen[$location['street']])) {
                $streets[] = [
                    'name' => $location['street']
                ];
                $seen[$location['street']] = true;
            }
        }
        
        // Sort streets alphabetically
        usort($streets, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        echo json_encode(['success' => true, 'data' => $streets]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?>