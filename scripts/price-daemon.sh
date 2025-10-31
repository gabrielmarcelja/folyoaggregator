#!/bin/bash

# FolyoAggregator Price Collection Daemon
# Runs price collector in background with auto-restart

SCRIPT_DIR="/var/www/html/folyoaggregator"
LOG_DIR="$SCRIPT_DIR/logs"
PID_FILE="$LOG_DIR/price-collector.pid"
LOG_FILE="$LOG_DIR/price-collector-daemon.log"

# Create log directory if it doesn't exist
mkdir -p $LOG_DIR

# Function to start the collector
start_collector() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat $PID_FILE)
        if ps -p $PID > /dev/null 2>&1; then
            echo "Price collector already running with PID $PID"
            return 1
        fi
    fi

    echo "Starting price collector daemon..."
    cd $SCRIPT_DIR

    # Start collector in background
    nohup php scripts/price-collector.php \
        --limit=50 \
        --interval=60 \
        >> $LOG_FILE 2>&1 &

    # Save PID
    echo $! > $PID_FILE
    echo "Price collector started with PID $(cat $PID_FILE)"
    echo "Log file: $LOG_FILE"
    return 0
}

# Function to stop the collector
stop_collector() {
    if [ ! -f "$PID_FILE" ]; then
        echo "Price collector is not running (PID file not found)"
        return 1
    fi

    PID=$(cat $PID_FILE)

    if ps -p $PID > /dev/null 2>&1; then
        echo "Stopping price collector (PID: $PID)..."
        kill -TERM $PID
        sleep 2

        # Force kill if still running
        if ps -p $PID > /dev/null 2>&1; then
            echo "Force stopping..."
            kill -9 $PID
        fi

        rm -f $PID_FILE
        echo "Price collector stopped"
    else
        echo "Price collector not running (PID $PID not found)"
        rm -f $PID_FILE
    fi
    return 0
}

# Function to check status
check_status() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat $PID_FILE)
        if ps -p $PID > /dev/null 2>&1; then
            echo "✅ Price collector is running (PID: $PID)"

            # Show last 5 lines of log
            echo ""
            echo "Recent log entries:"
            tail -5 $LOG_FILE
            return 0
        else
            echo "⚠️  Price collector is not running (stale PID file)"
            rm -f $PID_FILE
            return 1
        fi
    else
        echo "❌ Price collector is not running"
        return 1
    fi
}

# Function to restart
restart_collector() {
    echo "Restarting price collector..."
    stop_collector
    sleep 2
    start_collector
}

# Parse command
case "$1" in
    start)
        start_collector
        ;;
    stop)
        stop_collector
        ;;
    restart)
        restart_collector
        ;;
    status)
        check_status
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 1
        ;;
esac

exit $?