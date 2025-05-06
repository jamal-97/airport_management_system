<?php
session_start();
require_once '../db_connection.php';

// Check if user is logged in and is a traveller
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'traveller') {
    header("Location: ../login.php?error=unauthorized");
    exit();
}

// Get user ID
$user_id = $_SESSION['user_id'];

// Initialize variables
$departure_airport = isset($_GET['departure']) ? trim($_GET['departure']) : '';
$arrival_airport = isset($_GET['arrival']) ? trim($_GET['arrival']) : '';
$departure_date = isset($_GET['date']) ? trim($_GET['date']) : '';
$passengers = isset($_GET['passengers']) ? intval($_GET['passengers']) : 1;
$travel_class = isset($_GET['class']) ? trim($_GET['class']) : 'economy';

// Get a specific flight if flight_id is provided
$selected_flight = null;
if (isset($_GET['flight_id'])) {
    $flight_id = intval($_GET['flight_id']);
    $query = "SELECT f.id, f.flight_number, f.airline_id, f.departure_airport, f.arrival_airport, 
             f.departure_time, f.arrival_time, f.status, f.terminal, f.gate,
             a.name as airline_name, a.logo as airline_logo,
             ac.name as aircraft_name, ac.model as aircraft_model,
             p.amount as price, p.is_promotion
             FROM flights f
             JOIN airlines a ON f.airline_id = a.id
             JOIN aircraft ac ON f.aircraft_id = ac.id
             JOIN prices p ON f.id = p.flight_id
             WHERE f.id = ? AND f.departure_time > NOW() AND f.status = 'scheduled'";
    $result = $db->executeQuery($query, "i", [$flight_id]);
    
    if ($result && count($result) > 0) {
        $selected_flight = $result[0];
        
        // Set search parameters from the selected flight
        $departure_airport = $selected_flight['departure_airport'];
        $arrival_airport = $selected_flight['arrival_airport'];
        $departure_date = date('Y-m-d', strtotime($selected_flight['departure_time']));
    }
}

// Get all airports for dropdown
$query = "SELECT code, name, city, country FROM airports ORDER BY name";
$airports = $db->executeQuery($query);

// Get search results if form is submitted
$search_results = [];
if (!empty($departure_airport) && !empty($arrival_airport) && !empty($departure_date)) {
    $query = "SELECT f.id, f.flight_number, f.airline_id, f.departure_airport, f.arrival_airport, 
             f.departure_time, f.arrival_time, f.status, f.terminal, f.gate,
             a.name as airline_name, a.logo as airline_logo,
             ac.name as aircraft_name, ac.model as aircraft_model,
             p.amount as price, p.is_promotion,
             COUNT(b.id) as booked_seats, ac.capacity
             FROM flights f
             JOIN airlines a ON f.airline_id = a.id
             JOIN aircraft ac ON f.aircraft_id = ac.id
             JOIN prices p ON f.id = p.flight_id
             LEFT JOIN bookings b ON f.id = b.flight_id AND b.status IN ('confirmed', 'checked_in')
             WHERE f.departure_airport = ? 
             AND f.arrival_airport = ?
             AND DATE(f.departure_time) = ?
             AND f.departure_time > NOW()
             AND f.status = 'scheduled'
             AND p.class = ?
             GROUP BY f.id
             HAVING booked_seats < ac.capacity
             ORDER BY p.amount ASC, f.departure_time ASC";
    
    $search_results = $db->executeQuery($query, "ssss", [$departure_airport, $arrival_airport, $departure_date, $travel_class]);
}

// Handle booking submission
if (isset($_POST['book_flight']) && isset($_POST['flight_id'])) {
    $flight_id = intval($_POST['flight_id']);
    $passenger_count = intval($_POST['passenger_count']);
    $travel_class = trim($_POST['travel_class']);
    
    // Get flight and price information
    $query = "SELECT f.id, f.flight_number, f.departure_time, p.amount as price,
             (ac.capacity - COUNT(b.id)) as available_seats
             FROM flights f
             JOIN prices p ON f.id = p.flight_id
             JOIN aircraft ac ON f.aircraft_id = ac.id
             LEFT JOIN bookings b ON f.id = b.flight_id AND b.status IN ('confirmed', 'checked_in')
             WHERE f.id = ? AND p.class = ? AND f.departure_time > NOW() AND f.status = 'scheduled'
             GROUP BY f.id";
    $result = $db->executeQuery($query, "is", [$flight_id, $travel_class]);
    
    if ($result && count($result) > 0) {
        $flight = $result[0];
        
        // Check if enough seats are available
        if ($flight['available_seats'] >= $passenger_count) {
            // Calculate total price
            $total_price = $flight['price'] * $passenger_count;
            
            // Generate unique booking reference
            $booking_reference = generateBookingReference();
            
            // Create booking transaction
            $query = "INSERT INTO booking_transactions (user_id, amount, status, created_at)
                    VALUES (?, ?, 'pending', NOW())";
            $result = $db->executeQuery($query, "id", [$user_id, $total_price]);
            
            if ($result) {
                $transaction_id = $result['insert_id'];
                
                // Store booking information in session for payment processing
                $_SESSION['booking'] = [
                    'transaction_id' => $transaction_id,
                    'flight_id' => $flight_id,
                    'flight_number' => $flight['flight_number'],
                    'departure_time' => $flight['departure_time'],
                    'passenger_count' => $passenger_count,
                    'travel_class' => $travel_class,
                    'price_per_passenger' => $flight['price'],
                    'total_price' => $total_price,
                    'booking_reference' => $booking_reference
                ];
                
                // Redirect to payment page
                header("Location: payment.php");
                exit();
            } else {
                $error = "Failed to create booking transaction. Please try again.";
            }
        } else {
            $error = "Not enough seats available. Please choose fewer passengers or another flight.";
        }
    } else {
        $error = "Flight not found or no longer available. Please search again.";
    }
}

// Include header
$page_title = "Purchase Tickets";
include '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Purchase Flight Tickets</h1>
</div>

<!-- Flight Search Form -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Search Flights</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="purchase_tickets.php" id="flight-search-form">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label for="departure">From</label>
                    <select name="departure" id="departure" class="form-control select2" required>
                        <option value="">Select Departure Airport</option>
                        <?php foreach ($airports as $airport): ?>
                            <option value="<?php echo $airport['code']; ?>" <?php echo ($departure_airport == $airport['code']) ? 'selected' : ''; ?>>
                                <?php echo $airport['code'] . ' - ' . $airport['name'] . ', ' . $airport['city']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label for="arrival">To</label>
                    <select name="arrival" id="arrival" class="form-control select2" required>
                        <option value="">Select Arrival Airport</option>
                        <?php foreach ($airports as $airport): ?>
                            <option value="<?php echo $airport['code']; ?>" <?php echo ($arrival_airport == $airport['code']) ? 'selected' : ''; ?>>
                                <?php echo $airport['code'] . ' - ' . $airport['name'] . ', ' . $airport['city']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2 mb-3">
                    <label for="date">Departure Date</label>
                    <input type="date" name="date" id="date" class="form-control" value="<?php echo $departure_date; ?>" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="col-md-2 mb-3">
                    <label for="passengers">Passengers</label>
                    <select name="passengers" id="passengers" class="form-control">
                        <?php for ($i = 1; $i <= 9; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($passengers == $i) ? 'selected' : ''; ?>>
                                <?php echo $i; ?> Passenger<?php echo ($i > 1) ? 's' : ''; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-2 mb-3">
                    <label for="class">Class</label>
                    <select name="class" id="class" class="form-control">
                        <option value="economy" <?php echo ($travel_class == 'economy') ? 'selected' : ''; ?>>Economy</option>
                        <option value="premium_economy" <?php echo ($travel_class == 'premium_economy') ? 'selected' : ''; ?>>Premium Economy</option>
                        <option value="business" <?php echo ($travel_class == 'business') ? 'selected' : ''; ?>>Business</option>
                        <option value="first" <?php echo ($travel_class == 'first') ? 'selected' : ''; ?>>First Class</option>
                    </select>
                </div>
            </div>
            
            <div class="text-right">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search Flights
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger">
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<!-- Search Results -->
<?php if (!empty($search_results) || $selected_flight): ?>
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Available Flights</h5>
        </div>
        <div class="card-body">
            <?php if ($selected_flight): ?>
                <!-- Single Flight Details -->
                <div class="alert alert-info mb-4">
                    <i class="fas fa-info-circle"></i> You are viewing details for flight <?php echo $selected_flight['flight_number']; ?>.
                    <a href="purchase_tickets.php" class="alert-link">Click here</a> to search for more flights.
                </div>
                
                <div class="flight-card mb-4">
                    <div class="flight-card-header">
                        <div>
                            <h4><?php echo $selected_flight['airline_name']; ?></h4>
                            <span><?php echo $selected_flight['flight_number']; ?></span>
                        </div>
                        <div>
                            <span class="status-badge badge-<?php echo getStatusBadgeClass($selected_flight['status']); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $selected_flight['status'])); ?>
                            </span>
                            <?php if ($selected_flight['is_promotion']): ?>
                                <span class="badge badge-success ml-2">Special Offer</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flight-card-body">
                        <div class="flight-route">
                            <div class="flight-city">
                                <div class="flight-city-code"><?php echo $selected_flight['departure_airport']; ?></div>
                                <div class="flight-city-name"><?php echo getAirportName($selected_flight['departure_airport']); ?></div>
                            </div>
                            <div class="flight-route-line">
                                <i class="fas fa-plane"></i>
                            </div>
                            <div class="flight-city">
                                <div class="flight-city-code"><?php echo $selected_flight['arrival_airport']; ?></div>
                                <div class="flight-city-name"><?php echo getAirportName($selected_flight['arrival_airport']); ?></div>
                            </div>
                        </div>
                        <div class="flight-time-info">
                            <div class="flight-time">
                                <div class="flight-time-value"><?php echo date('H:i', strtotime($selected_flight['departure_time'])); ?></div>
                                <div class="flight-time-label"><?php echo date('M d, Y', strtotime($selected_flight['departure_time'])); ?></div>
                            </div>
                            <div class="flight-duration">
                                <div class="flight-duration-value"><?php echo calculateFlightDuration($selected_flight['departure_time'], $selected_flight['arrival_time']); ?></div>
                                <div class="flight-duration-label">Duration</div>
                            </div>
                            <div class="flight-time">
                                <div class="flight-time-value"><?php echo date('H:i', strtotime($selected_flight['arrival_time'])); ?></div>
                                <div class="flight-time-label"><?php echo date('M d, Y', strtotime($selected_flight['arrival_time'])); ?></div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="flight-details">
                                    <p><strong>Aircraft:</strong> <?php echo $selected_flight['aircraft_name'] . ' (' . $selected_flight['aircraft_model'] . ')'; ?></p>
                                    <?php if ($selected_flight['terminal'] && $selected_flight['gate']): ?>
                                        <p><strong>Terminal:</strong> <?php echo $selected_flight['terminal']; ?></p>
                                        <p><strong>Gate:</strong> <?php echo $selected_flight['gate']; ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="flight-price-info text-center">
                                    <h3 class="text-success">$<?php echo number_format($selected_flight['price'], 2); ?></h3>
                                    <p class="text-muted">per passenger</p>
                                    
                                    <h4>Total: $<?php echo number_format($selected_flight['price'] * $passengers, 2); ?></h4>
                                    <p class="text-muted">for <?php echo $passengers; ?> passenger<?php echo ($passengers > 1) ? 's' : ''; ?></p>
                                    
                                    <form method="POST" action="purchase_tickets.php">
                                        <input type="hidden" name="flight_id" value="<?php echo $selected_flight['id']; ?>">
                                        <input type="hidden" name="passenger_count" value="<?php echo $passengers; ?>">
                                        <input type="hidden" name="travel_class" value="<?php echo $travel_class; ?>">
                                        
                                        <button type="submit" name="book_flight" class="btn btn-lg btn-primary">
                                            <i class="fas fa-shopping-cart"></i> Book Now
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif (count($search_results) > 0): ?>
                <!-- Flight Search Results -->
                <div class="flight-search-results">
                    <?php foreach ($search_results as $flight): ?>
                        <div class="flight-card mb-3">
                            <div class="flight-card-header">
                                <div>
                                    <h4><?php echo $flight['airline_name']; ?></h4>
                                    <span><?php echo $flight['flight_number']; ?></span>
                                </div>
                                <div>
                                    <span class="status-badge badge-<?php echo getStatusBadgeClass($flight['status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $flight['status'])); ?>
                                    </span>
                                    <?php if ($flight['is_promotion']): ?>
                                        <span class="badge badge-success ml-2">Special Offer</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flight-card-body">
                                <div class="row">
                                    <div class="col-md-9">
                                        <div class="flight-route">
                                            <div class="flight-city">
                                                <div class="flight-city-code"><?php echo $flight['departure_airport']; ?></div>
                                                <div class="flight-city-name"><?php echo getAirportName($flight['departure_airport']); ?></div>
                                            </div>
                                            <div class="flight-route-line">
                                                <i class="fas fa-plane"></i>
                                            </div>
                                            <div class="flight-city">
                                                <div class="flight-city-code"><?php echo $flight['arrival_airport']; ?></div>
                                                <div class="flight-city-name"><?php echo getAirportName($flight['arrival_airport']); ?></div>
                                            </div>
                                        </div>
                                        <div class="flight-time-info">
                                            <div class="flight-time">
                                                <div class="flight-time-value"><?php echo date('H:i', strtotime($flight['departure_time'])); ?></div>
                                                <div class="flight-time-label"><?php echo date('M d, Y', strtotime($flight['departure_time'])); ?></div>
                                            </div>
                                            <div class="flight-duration">
                                                <div class="flight-duration-value"><?php echo calculateFlightDuration($flight['departure_time'], $flight['arrival_time']); ?></div>
                                                <div class="flight-duration-label">Duration</div>
                                            </div>
                                            <div class="flight-time">
                                                <div class="flight-time-value"><?php echo date('H:i', strtotime($flight['arrival_time'])); ?></div>
                                                <div class="flight-time-label"><?php echo date('M d, Y', strtotime($flight['arrival_time'])); ?></div>
                                            </div>
                                        </div>
                                        <div class="flight-details">
                                            <p><strong>Aircraft:</strong> <?php echo $flight['aircraft_name']; ?></p>
                                            <p><strong>Available Seats:</strong> <?php echo ($flight['capacity'] - $flight['booked_seats']); ?> of <?php echo $flight['capacity']; ?></p>
                                        </div>
                                    </div>
                                    <div class="col-md-3 d-flex flex-column align-items-center justify-content-center">
                                        <div class="flight-price-info text-center">
                                            <h3 class="text-success">$<?php echo number_format($flight['price'], 2); ?></h3>
                                            <p class="text-muted">per passenger</p>
                                            
                                            <a href="purchase_tickets.php?flight_id=<?php echo $flight['id']; ?>&passengers=<?php echo $passengers; ?>&class=<?php echo $travel_class; ?>" class="btn btn-primary">
                                                <i class="fas fa-info-circle"></i> View Details
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No flights found matching your criteria. Please try different dates or destinations.
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
    $(document).ready(function() {
        // Initialize select2 for airports dropdown
        if ($.fn.select2) {
            $('.select2').select2({
                theme: 'bootstrap4',
                placeholder: 'Select an airport',
                allowClear: true
            });
        }
        
        // Form validation
        $('#flight-search-form').submit(function(e) {
            const departure = $('#departure').val();
            const arrival = $('#arrival').val();
            
            if (departure === arrival) {
                e.preventDefault();
                alert('Departure and arrival airports cannot be the same.');
                return false;
            }
            
            return true;
        });
    });
</script>

<?php
// Helper functions
function calculateFlightDuration($departure, $arrival) {
    $dep = new DateTime($departure);
    $arr = new DateTime($arrival);
    $interval = $dep->diff($arr);
    
    $hours = $interval->h + ($interval->days * 24);
    $minutes = $interval->i;
    
    return $hours . 'h ' . $minutes . 'm';
}

function getAirportName($code) {
    // This would typically come from a database lookup
    // For simplicity, we'll use a static array
    $airports = [
        'JFK' => 'New York',
        'LAX' => 'Los Angeles',
        'ORD' => 'Chicago',
        'LHR' => 'London',
        'CDG' => 'Paris',
        'DXB' => 'Dubai',
        'HKG' => 'Hong Kong',
        'SYD' => 'Sydney',
        'SIN' => 'Singapore',
        'DEL' => 'New Delhi',
        'DAC' => 'Dhaka'
    ];
    
    return isset($airports[$code]) ? $airports[$code] : $code;
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'scheduled':
            return 'secondary';
        case 'boarding':
        case 'landed':
            return 'info';
        case 'departed':
        case 'in_air':
            return 'primary';
        case 'arrived':
            return 'success';
        case 'delayed':
            return 'warning';
        case 'cancelled':
            return 'danger';
        default:
            return 'secondary';
    }
}

function generateBookingReference() {
    // Generate a unique 6-character booking reference
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $reference = '';
    
    for ($i = 0; $i < 6; $i++) {
        $reference .= $characters[rand(0, strlen($characters) - 1)];
    }
    
    return $reference;
}

include '../includes/footer.php';
?>