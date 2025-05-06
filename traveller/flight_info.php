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

// Fetch user's bookings
$query = "SELECT b.id as booking_id, b.booking_reference, b.booking_date, b.status as booking_status, 
          b.seat_number, b.class, b.price, b.created_at,
          f.id as flight_id, f.flight_number, f.airline_id, f.departure_airport, f.arrival_airport, 
          f.departure_time, f.arrival_time, f.status as flight_status, f.terminal, f.gate,
          a.name as airline_name, a.logo as airline_logo,
          ac.name as aircraft_name, ac.model as aircraft_model,
          ci.boarding_pass_number, ci.check_in_time
          FROM bookings b
          JOIN flights f ON b.flight_id = f.id
          JOIN airlines a ON f.airline_id = a.id
          JOIN aircraft ac ON f.aircraft_id = ac.id
          LEFT JOIN check_ins ci ON b.id = ci.booking_id
          WHERE b.user_id = ?
          ORDER BY f.departure_time DESC";

$bookings = $db->executeQuery($query, "i", [$user_id]);

// Group bookings by status (upcoming, past, cancelled)
$upcoming_flights = [];
$past_flights = [];
$cancelled_flights = [];

$current_time = time();

foreach ($bookings as $booking) {
    $departure_time = strtotime($booking['departure_time']);
    
    if ($booking['booking_status'] === 'cancelled' || $booking['flight_status'] === 'cancelled') {
        $cancelled_flights[] = $booking;
    } elseif ($departure_time > $current_time) {
        $upcoming_flights[] = $booking;
    } else {
        $past_flights[] = $booking;
    }
}

// Include header
$page_title = "My Flights";
include '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>My Flights</h1>
    <div>
        <a href="purchase_tickets.php" class="btn btn-primary">
            <i class="fas fa-ticket-alt"></i> Book New Flight
        </a>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="flightTabs" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" id="upcoming-tab" data-toggle="tab" href="#upcoming" role="tab" aria-controls="upcoming" aria-selected="true">
            Upcoming Flights (<?php echo count($upcoming_flights); ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="past-tab" data-toggle="tab" href="#past" role="tab" aria-controls="past" aria-selected="false">
            Past Flights (<?php echo count($past_flights); ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="cancelled-tab" data-toggle="tab" href="#cancelled" role="tab" aria-controls="cancelled" aria-selected="false">
            Cancelled (<?php echo count($cancelled_flights); ?>)
        </a>
    </li>
</ul>

<!-- Tab Content -->
<div class="tab-content" id="flightTabsContent">
    <!-- Upcoming Flights Tab -->
    <div class="tab-pane fade show active" id="upcoming" role="tabpanel" aria-labelledby="upcoming-tab">
        <?php if (count($upcoming_flights) > 0): ?>
            <div class="flight-info-container">
                <?php foreach ($upcoming_flights as $flight): ?>
                    <?php
                    // Calculate time remaining until flight
                    $departure_time = strtotime($flight['departure_time']);
                    $current_time = time();
                    $time_remaining = $departure_time - $current_time;
                    $days_remaining = floor($time_remaining / (60 * 60 * 24));
                    $hours_remaining = floor(($time_remaining % (60 * 60 * 24)) / (60 * 60));
                    
                    // Determine if check-in is available (typically 24-48 hours before flight)
                    $check_in_available = ($time_remaining <= 48 * 60 * 60 && $time_remaining > 0);
                    
                    // Determine if cancellation is available (typically >24 hours before flight)
                    $cancellation_available = ($time_remaining > 24 * 60 * 60);
                    ?>
                    <div class="flight-card">
                        <div class="flight-card-header">
                            <div>
                                <h4><?php echo $flight['airline_name']; ?></h4>
                                <span><?php echo $flight['flight_number']; ?></span>
                            </div>
                            <div>
                                <span class="status-badge badge-<?php echo getStatusBadgeClass($flight['flight_status']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $flight['flight_status'])); ?>
                                </span>
                            </div>
                        </div>
                        <div class="flight-card-body">
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
                            
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="flight-details">
                                        <p><strong>Booking Reference:</strong> <?php echo $flight['booking_reference']; ?></p>
                                        <p><strong>Aircraft:</strong> <?php echo $flight['aircraft_name'] . ' (' . $flight['aircraft_model'] . ')'; ?></p>
                                        <?php if ($flight['seat_number']): ?>
                                            <p><strong>Seat:</strong> <?php echo $flight['seat_number']; ?></p>
                                        <?php endif; ?>
                                        <p><strong>Class:</strong> <?php echo ucfirst($flight['class']); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="flight-details">
                                        <?php if ($flight['terminal'] && $flight['gate']): ?>
                                            <p><strong>Terminal:</strong> <?php echo $flight['terminal']; ?></p>
                                            <p><strong>Gate:</strong> <?php echo $flight['gate']; ?></p>
                                        <?php endif; ?>
                                        <p><strong>Booked on:</strong> <?php echo date('M d, Y', strtotime($flight['created_at'])); ?></p>
                                        <?php if (!empty($flight['boarding_pass_number'])): ?>
                                            <p><strong>Boarding Pass:</strong> <?php echo $flight['boarding_pass_number']; ?></p>
                                            <p><strong>Checked in:</strong> <?php echo date('M d, Y H:i', strtotime($flight['check_in_time'])); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flight-countdown mt-3">
                                <div class="text-center">
                                    <?php if ($days_remaining > 0): ?>
                                        <div class="countdown-value"><?php echo $days_remaining; ?> days, <?php echo $hours_remaining; ?> hours</div>
                                    <?php else: ?>
                                        <div class="countdown-value"><?php echo $hours_remaining; ?> hours</div>
                                    <?php endif; ?>
                                    <div class="countdown-label">until departure</div>
                                </div>
                            </div>
                        </div>
                        <div class="flight-card-footer">
                            <div>
                                <span class="text-success font-weight-bold">$<?php echo number_format($flight['price'], 2); ?></span>
                            </div>
                            <div class="button-group">
                                <?php if (!empty($flight['boarding_pass_number'])): ?>
                                    <a href="view_boarding_pass.php?booking_id=<?php echo $flight['booking_id']; ?>" class="btn btn-success">
                                        <i class="fas fa-ticket-alt"></i> Boarding Pass
                                    </a>
                                <?php elseif ($check_in_available): ?>
                                    <a href="check_in.php?booking_id=<?php echo $flight['booking_id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-check-square"></i> Check-in
                                    </a>
                                <?php endif; ?>
                                
                                <a href="booking_details.php?id=<?php echo $flight['booking_id']; ?>" class="btn btn-info">
                                    <i class="fas fa-info-circle"></i> Details
                                </a>
                                
                                <?php if ($cancellation_available): ?>
                                    <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#cancelBookingModal" data-booking-id="<?php echo $flight['booking_id']; ?>" data-flight-number="<?php echo $flight['flight_number']; ?>" data-booking-reference="<?php echo $flight['booking_reference']; ?>">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> You don't have any upcoming flights. <a href="purchase_tickets.php">Book a flight now!</a>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Past Flights Tab -->
    <div class="tab-pane fade" id="past" role="tabpanel" aria-labelledby="past-tab">
        <?php if (count($past_flights) > 0): ?>
            <div class="flight-info-container">
                <?php foreach ($past_flights as $flight): ?>
                    <div class="flight-card">
                        <div class="flight-card-header">
                            <div>
                                <h4><?php echo $flight['airline_name']; ?></h4>
                                <span><?php echo $flight['flight_number']; ?></span>
                            </div>
                            <div>
                                <span class="status-badge badge-<?php echo getStatusBadgeClass($flight['flight_status']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $flight['flight_status'])); ?>
                                </span>
                            </div>
                        </div>
                        <div class="flight-card-body">
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
                            
                            <div class="flight-details mt-3">
                                <p><strong>Booking Reference:</strong> <?php echo $flight['booking_reference']; ?></p>
                                <p><strong>Aircraft:</strong> <?php echo $flight['aircraft_name'] . ' (' . $flight['aircraft_model'] . ')'; ?></p>
                                <?php if ($flight['seat_number']): ?>
                                    <p><strong>Seat:</strong> <?php echo $flight['seat_number']; ?></p>
                                <?php endif; ?>
                                <p><strong>Class:</strong> <?php echo ucfirst($flight['class']); ?></p>
                            </div>
                        </div>
                        <div class="flight-card-footer">
                            <div>
                                <span class="text-success font-weight-bold">$<?php echo number_format($flight['price'], 2); ?></span>
                            </div>
                            <div>
                                <a href="booking_details.php?id=<?php echo $flight['booking_id']; ?>" class="btn btn-info">
                                    <i class="fas fa-info-circle"></i> Details
                                </a>
                                <a href="book_similar.php?flight_id=<?php echo $flight['flight_id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-redo"></i> Book Similar
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> You don't have any past flights.
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Cancelled Flights Tab -->
    <div class="tab-pane fade" id="cancelled" role="tabpanel" aria-labelledby="cancelled-tab">
        <?php if (count($cancelled_flights) > 0): ?>
            <div class="flight-info-container">
                <?php foreach ($cancelled_flights as $flight): ?>
                    <div class="flight-card">
                        <div class="flight-card-header">
                            <div>
                                <h4><?php echo $flight['airline_name']; ?></h4>
                                <span><?php echo $flight['flight_number']; ?></span>
                            </div>
                            <div>
                                <span class="status-badge badge-danger">Cancelled</span>
                            </div>
                        </div>
                        <div class="flight-card-body">
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
                            
                            <div class="flight-details mt-3">
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-circle"></i> This flight has been cancelled. 
                                    <?php if ($flight['booking_status'] === 'refunded'): ?>
                                        Your payment has been refunded.
                                    <?php elseif ($flight['booking_status'] === 'cancelled'): ?>
                                        Your booking has been cancelled.
                                    <?php else: ?>
                                        Please contact customer support for assistance.
                                    <?php endif; ?>
                                </div>
                                <p><strong>Booking Reference:</strong> <?php echo $flight['booking_reference']; ?></p>
                                <p><strong>Aircraft:</strong> <?php echo $flight['aircraft_name'] . ' (' . $flight['aircraft_model'] . ')'; ?></p>
                                <p><strong>Class:</strong> <?php echo ucfirst($flight['class']); ?></p>
                            </div>
                        </div>
                        <div class="flight-card-footer">
                            <div>
                                <span class="text-muted font-weight-bold">$<?php echo number_format($flight['price'], 2); ?></span>
                            </div>
                            <div>
                                <a href="booking_details.php?id=<?php echo $flight['booking_id']; ?>" class="btn btn-info">
                                    <i class="fas fa-info-circle"></i> Details
                                </a>
                                <a href="book_similar.php?flight_id=<?php echo $flight['flight_id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-redo"></i> Book Similar
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> You don't have any cancelled flights.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Cancel Booking Modal -->
<div class="modal fade" id="cancelBookingModal" tabindex="-1" role="dialog" aria-labelledby="cancelBookingModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="cancel_booking.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelBookingModalLabel">Cancel Flight Booking</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="booking_id" id="booking_id">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> Are you sure you want to cancel your booking? This action cannot be undone.
                    </div>
                    
                    <div class="form-group">
                        <label>Flight Number:</label>
                        <div id="flight_number" class="font-weight-bold"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Booking Reference:</label>
                        <div id="booking_reference" class="font-weight-bold"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="cancellation_reason">Reason for Cancellation:</label>
                        <select name="cancellation_reason" id="cancellation_reason" class="form-control" required>
                            <option value="">Select Reason</option>
                            <option value="schedule_change">Change of Schedule</option>
                            <option value="personal">Personal Reasons</option>
                            <option value="business">Business Trip Cancelled</option>
                            <option value="illness">Illness or Medical Issue</option>
                            <option value="alternative">Found Alternative Transport</option>
                            <option value="price">Found Better Price</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="comments">Additional Comments:</label>
                        <textarea name="comments" id="comments" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" name="confirm_cancellation" id="confirm_cancellation" class="custom-control-input" required>
                            <label class="custom-control-label" for="confirm_cancellation">I understand that by cancelling this booking, I may incur cancellation fees as per the booking terms and conditions.</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" name="cancel_booking" class="btn btn-danger">Cancel Booking</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        // Cancel Booking Modal
        $('#cancelBookingModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var bookingId = button.data('booking-id');
            var flightNumber = button.data('flight-number');
            var bookingReference = button.data('booking-reference');
            
            var modal = $(this);
            modal.find('#booking_id').val(bookingId);
            modal.find('#flight_number').text(flightNumber);
            modal.find('#booking_reference').text(bookingReference);
            
            // Reset fields
            modal.find('#cancellation_reason').val('');
            modal.find('#comments').val('');
            modal.find('#confirm_cancellation').prop('checked', false);
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

include '../includes/footer.php';
?>