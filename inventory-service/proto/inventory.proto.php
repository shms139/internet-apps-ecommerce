syntax = "proto3";

package inventory;

service InventoryService {
// Required operation 1: Get available stock.
rpc GetStock (GetStockRequest) returns (GetStockResponse);

// Required operation 2: Reserve stock atomically.
rpc ReserveStock (ReserveStockRequest) returns (ReserveStockResponse);

// Required operation 3: Release a previous reservation.
rpc ReleaseStock (ReleaseStockRequest) returns (ReleaseStockResponse);

// Administrative operation: Synchronize product stock.
rpc SetStock (SetStockRequest) returns (SetStockResponse);
}

message GetStockRequest {
int64 product_id = 1;
}

message GetStockResponse {
bool found = 1;
int64 available_quantity = 2;
}

message StockItem {
int64 product_id = 1;
int32 quantity = 2;
}

message ReserveStockRequest {
string reservation_id = 1;
repeated StockItem items = 2;
}

message ReserveStockResponse {
bool success = 1;
string message = 2;
}

message ReleaseStockRequest {
string reservation_id = 1;
}

message ReleaseStockResponse {
bool success = 1;
string message = 2;
}

message SetStockRequest {
int64 product_id = 1;
int64 quantity = 2;
}

message SetStockResponse {
bool success = 1;
string message = 2;
}
