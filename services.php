<?php

// Redirect if the user doesn't have access to services
if ($admin["access"]["services"] != 1) {
    header("Location: " . site_url("admin"));
    exit();
}

// Retrieve and store data from the session
if ($_SESSION["client"]["data"]) {
    $data = $_SESSION["client"]["data"];
    foreach ($data as $key => $value) {
        $$key = $value;
    }
    unset($_SESSION["client"]);
}

// Determine the page or action based on the URL route
if (!route(2)) {
    $page = 1;
} elseif (is_numeric(route(2))) {
    $page = route(2);
} elseif (!is_numeric(route(2))) {
    $action = route(2);
}

if (empty($action)) {
    // Fetch settings from the database
    $query = $conn->query("SELECT * FROM settings", PDO::FETCH_ASSOC);
    if ($query->rowCount()) {
        foreach ($query as $row) {
            $siraal = $row['servis_siralama'];
        }
    }

    // Handle updating the service order
    if (!empty($_GET["siralama"])) {
        $updatesiralama = $conn->prepare("UPDATE settings SET servis_siralama=:servis_siralama WHERE id=:id ");
        $updatesiralama->execute(array("servis_siralama" => $_GET["siralama"], "id" => 1));

        header("Location: " . site_url("admin/services"));
        exit();
    }

    // Fetch services from the database based on the order
    $services = $conn->prepare("SELECT * FROM services RIGHT JOIN categories ON categories.category_id = services.category_id LEFT JOIN service_api ON service_api.id = services.service_api ORDER BY categories.category_line, services.service_line " . $siraal);
    $services->execute();
    $services = $services->fetchAll(PDO::FETCH_ASSOC);
    $serviceList = array_group_by($services, 'category_name');
    require admin_view('services');
} elseif ($action == "new-service") {
    if ($_POST) {
        // Fetch default language from the database
        $language = $conn->prepare("SELECT * FROM languages WHERE default_language=:default");
        $language->execute(array("default" => 1));
        $language = $language->fetch(PDO::FETCH_ASSOC);

        foreach ($_POST as $key => $value) {
            $$key = $value;
        }
        $cat = intval(@$_POST["category"]);
        if (!$cat) {
            $cat = $category;
        }
        $name = mb_convert_encoding($_POST["name"][$language["language_code"]], "UTF-8", "UTF-8");
        $multiName = json_encode($_POST["name"]);

        // Validate form inputs
        $error = 0;
        $errorText = "";
        $icon = "";

        if (empty($name)) {
            $error = 1;
            $errorText = "Product name cannot be blank";
            $icon = "error";
        } elseif (empty($package)) {
            $error = 1;
            $errorText = "The product package cannot be empty";
            $icon = "error";
        } elseif (empty($category)) {
            $error = 1;
            $errorText = "Product category cannot be empty";
            $icon = "error";
        } elseif (!is_numeric($min)) {
            $error = 1;
            $errorText = "Minimum order quantity cannot be empty";
            $icon = "error";
        } elseif ($package != 2 && !is_numeric($max)) {
            $error = 1;
            $errorText = "Maximum order quantity cannot be empty";
            $icon = "error";
        } elseif ($min > $max) {
            $error = 1;
            $errorText = "Minimum order quantity cannot exceed the maximum order quantity";
            $icon = "error";
        } elseif ($mode != 1 && empty($provider)) {
            $error = 1;
            $errorText = "Service provider cannot be empty";
            $icon = "error";
        } elseif ($mode != 1 && empty($service)) {
            $error = 1;
            $errorText = "Service provider service information cannot be empty";
            $icon = "error";
        } elseif (empty($secret)) {
            $error = 1;
            $errorText = "Service privacy cannot be empty";
            $icon = "error";
        } elseif (empty($want_username)) {
            $error = 1;
            $errorText = "Order link cannot be empty";
            $icon = "error";
        } elseif (!is_numeric($price)) {
            $error = 1;
            $errorText = "The product price should consist of numbers";
            $icon = "error";
        }

        if ($error == 0) {
            // Set default values for refill days and hours if empty
            if (empty($refill_days)) {
                $refill_days = "30";
            }
            if (empty($refill_hours)) {
                $refill_hours = "24";
            }

            // Fetch API information from the database
            $api = $conn->prepare("SELECT * FROM service_api WHERE id=:id ");
            $api->execute(array("id" => $provider));
            $api = $api->fetch(PDO::FETCH_ASSOC);

            // Prepare data for insertion into the database
            if ($mode == 1) {
                $provider = 0;
                $service = 0;
            }
            if ($mode == 2 && $api["api_type"] == 1) {
                $smmapi = new SMMApi();
                $services = $smmapi->action(array('key' => $api["api_key"], 'action' => 'services'), $api["api_url"]);
                $balance = $smmapi->action(array('key' => $api["api_key"], 'action' => 'balance'), $api["api_url"]);

                foreach ($services as $apiService) {
                    if ($service == $apiService->service) {
                        $detail["min"] = $apiService->min;
                        $detail["max"] = $apiService->max;
                        $detail["rate"] = $apiService->rate;
                        $detail["currency"] = $balance->currency;
                        $detail = json_encode($detail);
                    }
                }
            } else {
                $detail = "";
            }

            // Insert new service into the database
            $row = $conn->query("SELECT * FROM services WHERE category_id='$category' ORDER BY service_line DESC LIMIT 1 ")->fetch(PDO::FETCH_ASSOC);
            $conn->beginTransaction();
            $insert = $conn->prepare("INSERT INTO services SET name_lang=:multiName, service_secret=:secret, service_api=:api, service_dripfeed=:dripfeed, instagram_second=:instagram_second, start_count=:start_count, instagram_private=:instagram_private, api_service=:api_service, api_detail=:detail, category_id=:category, service_line=:line, service_type=:type, service_package=:package, service_name=:name, service_price=:price, service_min=:min, service_max=:max, want_username=:want_username, service_speed=:speed, cancelbutton=:cancelbutton, show_refill=:show_refill, refill_days=:refill_days, refill_hours=:refill_hour ");
            $insert = $insert->execute(array("secret" => $secret, "multiName" => $multiName, "instagram_second" => $instagram_second, "dripfeed" => $dripfeed, "start_count" => $start_count, "instagram_private" => $instagram_private, "api" => $provider, "api_service" => $service, "detail" => $detail, "category" => $cat, "line" => $row["service_line"] + 1, "type" => 2, "package" => $package, "name" => $name, "price" => $price, "min" => $min, "max" => $max, "want_username" => $want_username, "speed" => $speed, "cancelbutton" => $cancelbutton, "show_refill" => $show_refill, "refill_days" => $refill_days, "refill_hour" => $refill_hours));

            if ($insert) {
                $conn->commit();
                $referrer = site_url("admin/services");
                $error = 1;
                $errorText = "Successful";
                $icon = "success";

                // Insert service update into the database
                $insert2 = $conn->prepare("INSERT INTO updates SET service_id=:s_id, action=:action, description=:description, date=:date ");
                $insert2 = $insert2->execute(array("s_id" => $service_id, "action" => "Added", "description" => "New Service added", "date" => date("Y-m-d H:i:s")));
            } else {
                $conn->rollBack();
                $error = 1;
                $errorText = "Unsuccessful";
                $icon = "error";
            }
        }

        echo json_encode(["t" => "error", "m" => $errorText, "s" => $icon, "r" => $referrer]);
    }
}

elseif ($action == "edit-service"):
    // Get the service ID from the route
    $service_id = route(3);
    // If the service ID doesn't exist, redirect to the services page
    if (!countRow(["table" => "services", "where" => ["service_id" => $service_id]])) {
        header("Location:" . site_url("admin/services"));
        exit();
    }
    if ($_POST):
        // Get the default language
        $language = $conn->prepare("SELECT * FROM languages WHERE default_language=:default");
        $language->execute(array("default" => 1));
        $language = $language->fetch(PDO::FETCH_ASSOC);
        foreach ($_POST as $key => $value) {
            $$key = $value;
        }
        $cat = intval(@$_POST["category"]);
        // Convert the product name to UTF-8 encoding
        $name = mb_convert_encoding($_POST["name"][$language["language_code"]], 'UTF-8', 'UTF-8');
        $multiName = json_encode($_POST["name"]);

        if ($package == 2): $max = $min; endif;
        // Get the service information
        $serviceInfo = $conn->prepare("SELECT * FROM services INNER JOIN service_api ON service_api.id = services.service_api WHERE service_id=:id ");
        $serviceInfo->execute(array("id" => route(3)));
        $serviceInfo = $serviceInfo->fetch(PDO::FETCH_ASSOC);

        // Validate form input
        if (empty($name)):
            $error = 1;
            $errorText = "Product name cannot be blank";
            $icon = "error";
        elseif (empty($package)):
            $error = 1;
            $errorText = "The product package cannot be empty";
            $icon = "error";
        elseif (empty($category)):
            $error = 1;
            $errorText = "Product category cannot be empty";
            $icon = "error";
        elseif (!is_numeric($min)):
            $error = 1;
            $errorText = "Minimum order quantity cannot be empty";
            $icon = "error";
        elseif ($package != 2 && !is_numeric($max)):
            $error = 1;
            $errorText = "Maximum order quantity cannot be empty";
            $icon = "error";
        elseif ($min > $max):
            $error = 1;
            $errorText = "Minimum order quantity cannot exceed the maximum order quantity";
            $icon = "error";
        elseif ($mode != 1 && empty($provider)):
            $error = 1;
            $errorText = "Service provider cannot be empty";
            $icon = "error";
        elseif ($mode != 1 && empty($service)):
            $error = 1;
            $errorText = "Service provider service information cannot be empty";
            $icon = "error";
        elseif (empty($secret)):
            $error = 1;
            $errorText = "Service privacy cannot be empty";
            $icon = "error";
        elseif (empty($want_username)):
            $error = 1;
            $errorText = "Order link cannot be empty";
            $icon = "error";
        elseif (!is_numeric($price)):
            $error = 1;
            $errorText = "The product price should consist of numbers";
            $icon = "error";
        else:
            // Get the service API details
            $api = $conn->prepare("SELECT * FROM service_api WHERE id=:id ");
            $api->execute(array("id" => $provider));
            $api = $api->fetch(PDO::FETCH_ASSOC);
            if ($mode == 1): $provider = 0; $service = 0; endif;
            if ($mode == 2 && $api["api_type"] == 1):
                // Call the SMM API to get service details and balance
                $smmapi = new SMMApi();
                $services = $smmapi->action(array('key' => $api["api_key"], 'action' => 'services'), $api["api_url"]);
                $balance = $smmapi->action(array('key' => $api["api_key"], 'action' => 'balance'), $api["api_url"]);
                foreach ($services as $apiService):
                    if ($service == $apiService->service):
                        $detail["min"] = $apiService->min;
                        $detail["max"] = $apiService->max;
                        $detail["rate"] = $apiService->rate;
                        $detail["currency"] = $balance->currency;
                        $detail = json_encode($detail);
                    endif;
                endforeach;
            else:
                $detail = "";
            endif;
            // Update the service
            if ($serviceInfo["category_id"] != $category):
                $row = $conn->query("SELECT * FROM services WHERE category_id='$category' ORDER BY service_line DESC LIMIT 1 ")->fetch(PDO::FETCH_ASSOC);
                $last_category = $serviceInfo["category_id"];
                $last_line = $serviceInfo["service_line"];
                $line = $row["service_line"] + 1;
            else:
                $line = $serviceInfo["service_line"];
            endif;
            $conn->beginTransaction();
            $update = $conn->prepare("UPDATE services SET api_detail=:detail, name_lang=:multiName, service_dripfeed=:dripfeed, api_servicetype=:type, instagram_second=:instagram_second, start_count=:start_count, instagram_private=:instagram_private, service_api=:api, api_service=:api_service, category_id=:category, service_package=:package, service_name=:name,service_price=:price, service_min=:min, service_secret=:secret, service_max=:max, want_username=:want_username, service_speed=:speed, cancelbutton=:cancelbutton, show_refill=:show_refill, refill_days=:refill_days, refill_hours=:refill_hour WHERE service_id=:id ");
            $update = $update->execute(array("id" => route(3), "multiName" => $multiName, "secret" => $secret, "type" => 2, "detail" => $detail, "dripfeed" => $dripfeed, "instagram_second" => $instagram_second, "start_count" => $start_count, "instagram_private" => $instagram_private, "api" => $provider, "api_service" => $service, "category" => $category, "package" => $package, "name" => $name, "price" => $price, "min" => $min, "max" => $max, "want_username" => $want_username, "speed" => $speed, "cancelbutton" => $cancelbutton, "show_refill" => $show_refill, "refill_days" => $refill_days, "refill_hour" => $refill_hours));
            if ($update):
                $conn->commit();
                $rows = $conn->prepare("SELECT * FROM services WHERE category_id=:c_id && service_line>=:line ");
                $rows->execute(array("c_id" => $last_category, "line" => $last_line));
                $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row):
                    $update = $conn->prepare("UPDATE services SET service_line=:line WHERE service_id=:id ");
                    $update->execute(array("line" => $row["service_line"] - 1, "id" => $row["service_id"]));
                endforeach;
                $error = 1;
                $errorText = "Successful";
                $icon = "success";
                $referrer = site_url("admin/services");

                if ($serviceInfo["show_refill"] != $show_refill):
                    if ($show_refill == "true"):
                        // Insert refill activation log
                        $insert2 = $conn->prepare("INSERT INTO updates SET service_id=:s_id, action=:action, description=:description, date=:date ");
                        $insert2 = $insert2->execute(array("s_id" => $service_id, "action" => "Refill Activated", "description" => "Refill Button has been activated", "date" => date("Y-m-d H:i:s")));
                    else:
                        // Insert refill deactivation log
                        $insert2 = $conn->prepare("INSERT INTO updates SET service_id=:s_id, action=:action, description=:description, date=:date ");
                        $insert2 = $insert2->execute(array("s_id" => $service_id, "action" => "Refill Deactivated", "description" => "Refill Button has been Deactivated", "date" => date("Y-m-d H:i:s")));
                    endif;
                endif;

                if ($serviceInfo["cancelbutton"] != $cancelbutton):
                    if ($cancelbutton == "1"):
                        // Insert cancel activation log
                        $insert2 = $conn->prepare("INSERT INTO updates SET service_id=:s_id, action=:action, description=:description, date=:date ");
                        $insert2 = $insert2->execute(array("s_id" => $service_id, "action" => "Cancel Activated", "description" => "Cancel Button has been activated", "date" => date("Y-m-d H:i:s")));
                    else:
                        // Insert cancel deactivation log
                        $insert2 = $conn->prepare("INSERT INTO updates SET service_id=:s_id, action=:action, description=:description, date=:date ");
                        $insert2 = $insert2->execute(array("s_id" => $service_id, "action" => "Cancel Deactivated", "description" => "Cancel Button has been Deactivated", "date" => date("Y-m-d H:i:s")));
                    endif;
                endif;
            endif;
        endif;
        break;

// Check if service price needs to be updated
if ($serviceInfo["service_price"] != $price) {
  $action = ($serviceInfo["service_price"] < $price) ? "Price Increased" : "Price Decreased";
  $description = "Price changed from " . $serviceInfo["service_price"] . " to $price";

  $insert2 = $conn->prepare("INSERT INTO updates (service_id, action, description, date) VALUES (:s_id, :action, :description, :date)");
  $insert2->execute(array(
    "s_id" => $service_id,
    "action" => $action,
    "description" => $description,
    "date" => date("Y-m-d H:i:s")
  ));
}

// Check if service minimum amount needs to be updated
if ($serviceInfo["service_min"] != $min) {
  $action = ($serviceInfo["service_min"] < $min) ? "Minimum Increased" : "Minimum Decreased";
  $description = "Minimum amount changed from " . $serviceInfo["service_min"] . " to $min";

  $insert2 = $conn->prepare("INSERT INTO updates (service_id, action, description, date) VALUES (:s_id, :action, :description, :date)");
  $insert2->execute(array(
    "s_id" => $service_id,
    "action" => $action,
    "description" => $description,
    "date" => date("Y-m-d H:i:s")
  ));
}

// Check if service maximum amount needs to be updated
if ($serviceInfo["service_max"] != $max) {
  $action = ($serviceInfo["service_max"] < $max) ? "Maximum Increased" : "Maximum Decreased";
  $description = "Maximum amount changed from " . $serviceInfo["service_max"] . " to $max";

  $insert2 = $conn->prepare("INSERT INTO updates (service_id, action, description, date) VALUES (:s_id, :action, :description, :date)");
  $insert2->execute(array(
    "s_id" => $service_id,
    "action" => $action,
    "description" => $description,
    "date" => date("Y-m-d H:i:s")
  ));
} else {
  $conn->rollBack();
  $error = 1;
  $errorText = "Unsuccessful";
  $icon = "error";
}

echo json_encode(["t" => "error", "m" => $errorText, "s" => $icon, "r" => $referrer]);

// Other code snippets...
