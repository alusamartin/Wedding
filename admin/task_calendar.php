<?php include 'include/init.php'; ?>
<?php
    require_staff_subscribed();

    $booking_id = (int)($_GET['booking_id'] ?? 0);
    $user_id = (int)($_GET['user_id'] ?? 0);
    $links = 'booking_id=' . $booking_id . '&user_id=' . $user_id;

    // Fetch events for FullCalendar
    $calendar_events = Events::get_events_for_calendar($booking_id);
    $events_json = json_encode($calendar_events);

    // Predefined Kenyan wedding events (if none exist yet)
    $sample_events = [
        ['title' => 'Ruracio Ceremony (Dowry Negotiation)', 'color' => '#c0392b'],
        ['title' => 'Church Blessing / Wedding Ceremony', 'color' => '#8e44ad'],
        ['title' => 'Reception (Garden/Hotel)', 'color' => '#27ae60'],
        ['title' => 'After-Party Celebration', 'color' => '#f39c12'],
        ['title' => 'VC Meeting (Wedding Committee)', 'color' => '#3498db'],
    ];

    // Open the calendar on the couple's wedding month, not today.
    $wedding_default = null;
    $booking = $booking_id > 0 ? Booking::find_by_booking_id($booking_id) : false;
    if ($booking && !empty($booking->wedding_date) && strtotime($booking->wedding_date) !== false) {
        $wedding_default = date('Y-m-d', strtotime($booking->wedding_date));
    }
?>
<?php $users_profile = Users::find_by_id($_SESSION['id']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset='utf-8' />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Task Calendar - Everafter Planner</title>
    <link href="css/bootstrap.min.css?v=4.6.2" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/modern-admin.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.materialdesignicons.com/2.1.19/css/materialdesignicons.min.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700" rel="stylesheet">
    <link href='calendar/fullcalendar.min.css' rel='stylesheet' />
    <script src='calendar/moment.min.js'></script>
    <script src='calendar/jquery-3.7.1.min.js'></script>
    <script src="js/bootstrap.min.js?v=4.6.2"></script>
    <script src='calendar/fullcalendar.min.js'></script>
    <style>
        #calendar { max-width:100%; margin: 0px auto; padding: 20px; background: white; }
        .fc-content { background: white; color: black; padding:3px; }
        .fc-title { text-transform: capitalize; }
        .legend-item { display: inline-block; margin-right: 15px; font-size: 12px; }
        .legend-color { display: inline-block; width: 12px; height: 12px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }
    </style>
</head>
<body>
<?php include_once 'include/sidebar.php'; ?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h4 class="h4 mt-4 ml-3"><i class="mdi mdi-calendar-text"></i> Wedding Task Calendar</h4>
    <div class="btn-toolbar mb-md-0">
        <div class="btn-group mr-2">
            <a class="btn btn-sm btn-primary" href="client_manage_account_details.php?<?= $links; ?>"><i class="mdi mdi-buffer mr-1"></i> Overview</a>
            <a class="btn btn-sm btn-info" href="guest_list.php?<?= $links; ?>"><i class="mdi mdi-account-multiple mr-1"></i> Guests</a>
            <a class="btn btn-sm btn-success" href="budget.php?<?= $links; ?>"><i class="mdi mdi-cash mr-1"></i> Budget</a>
            <a class="btn btn-sm btn-warning active" href="task_calendar.php?<?= $links; ?>"><i class="mdi mdi-calendar-text mr-1"></i> Calendar</a>
        </div>
    </div>
</div>

<?php if ($session->message()): ?>
    <div class="ml-3 mr-3"><?= $session->message(); ?></div>
<?php endif; ?>

<!-- Event Type Legend -->
<div class="ml-3 mb-3 d-flex justify-content-between flex-wrap">
    <div>
    <strong style="font-size: 12px; text-transform: uppercase; color: #888;">Event Types:</strong><br>
    <span class="legend-item"><span class="legend-color" style="background:#c0392b;"></span>Ruracio / Dowry</span>
    <span class="legend-item"><span class="legend-color" style="background:#8e44ad;"></span>Church Wedding</span>
    <span class="legend-item"><span class="legend-color" style="background:#27ae60;"></span>Reception</span>
    <span class="legend-item"><span class="legend-color" style="background:#f39c12;"></span>After-Party</span>
    <span class="legend-item"><span class="legend-color" style="background:#3498db;"></span>VC Meeting</span>
    <span class="legend-item"><span class="legend-color" style="background:#00897b;"></span>Send-off / Disco Matanga</span>
    </div>
    <?php if ($wedding_default !== null): ?>
    <button type="button" class="btn btn-sm btn-outline-primary" id="jumpToWedding" data-date="<?= $wedding_default; ?>">
        <i class="mdi mdi-calendar-heart mr-1"></i> Jump to Wedding Day
    </button>
    <?php endif; ?>
</div>
<script>
    $(function () {
        $('#jumpToWedding').on('click', function () {
            $('#calendar').fullCalendar('gotoDate', $(this).data('date'));
        });
    });
</script>

<div id='calendar'></div>

<!-- Add Event Modal -->
<div class="modal fade" id="addEventModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="post" action="task_event_save.php?<?= $links; ?>">
                <?= $session->csrf_field(); ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="mdi mdi-calendar-plus"></i> Add Wedding Event</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Event Type</label>
                        <select name="title" class="form-control" id="event_title">
                            <option value="">Select Event Type</option>
                            <option value="Ruracio Ceremony (Dowry Negotiation)">Ruracio Ceremony (Dowry Negotiation)</option>
                            <option value="Church Blessing / Wedding Ceremony">Church Blessing / Wedding Ceremony</option>
                            <option value="Reception (Garden/Hotel)">Reception (Garden/Hotel)</option>
                            <option value="After-Party Celebration">After-Party Celebration</option>
                            <option value="VC Meeting (Wedding Committee)">VC Meeting (Wedding Committee)</option>
                            <option value="Civil Registration">Civil Registration</option>
                            <option value="Bridal Shower">Bridal Shower</option>
                            <option value="Engagement Party">Engagement Party</option>
                            <option value="Send-off / Disco Matanga">Send-off / Disco Matanga</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Location / Venue</label>
                        <select name="location" class="form-control">
                            <option value="">Select Venue</option>
                            <option>Nairobi - Carnivore Grounds</option>
                            <option>Nairobi - Garden Wedding (Karen)</option>
                            <option>Nairobi - Hotel (Serena / Sankara)</option>
                            <option>Mombasa - Diani Beach</option>
                            <option>Nakuru - Elementaita</option>
                            <option>Nyeri - Upcountry Home</option>
                            <option>Kisumu - Lakeside</option>
                            <option>Eldoret - Farm</option>
                            <option>Kiambu - Church + Reception</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Start Date/Time</label>
                            <input type="datetime-local" name="start" class="form-control" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>End Date/Time</label>
                            <input type="datetime-local" name="end" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

</div></div>

<script>
    document.getElementById('event_title').addEventListener('change', function() {
        var colors = {
            'Ruracio Ceremony (Dowry Negotiation)': '#c0392b',
            'Church Blessing / Wedding Ceremony': '#8e44ad',
            'Reception (Garden/Hotel)': '#27ae60',
            'After-Party Celebration': '#f39c12',
            'VC Meeting (Wedding Committee)': '#3498db',
            'Civil Registration': '#2c3e50',
            'Bridal Shower': '#e91e63',
            'Engagement Party': '#ff9800',
            'Send-off / Disco Matanga': '#00897b',
            'Other': '#607d8b'
        };
        document.getElementById('event_color').value = colors[this.value] || '#607d8b';
    });
</script>
<script>
    $(document).ready(function() {
        var events = <?= $events_json; ?>;

        // If no events, show sample Kenyan wedding timeline
        if (events.length === 0) {
            var base = moment();
            events = [
                { title: 'Ruracio Ceremony', start: base.clone().add(1, 'days').format(), color: '#c0392b' },
                { title: 'Church Wedding', start: base.clone().add(7, 'days').format(), color: '#8e44ad' },
                { title: 'Reception', start: base.clone().add(7, 'days').add(4, 'hours').format(), color: '#27ae60' },
                { title: 'After-Party', start: base.clone().add(7, 'days').add(8, 'hours').format(), color: '#f39c12' }
            ];
        }

        $('#calendar').fullCalendar({
            header: {
                left: 'prev,next today',
                center: 'title',
                right: 'month,agendaWeek,agendaDay'
            },
            defaultDate: <?= json_encode($wedding_default); ?>,
            editable: true,
            eventLimit: true,
            events: events,
            selectable: true,
            selectHelper: true,
            select: function(start, end) {
                $('#addEventModal').modal('show');
                $('input[name="start"]').val(start.format('YYYY-MM-DDTHH:mm'));
                $('input[name="end"]').val(end.format('YYYY-MM-DDTHH:mm'));
            },
            eventClick: function(event) {
                if (confirm('Edit event: ' + event.title + '?')) {
                    window.location.href = 'task_editEventTitle.php?id=' + event.id + '&<?= $links; ?>';
                }
            }
        });
    });
</script>
</body>
</html>