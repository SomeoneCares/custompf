#!/bin/sh
#
# FlexiWAN SD-WAN Integration Daemon
# 
# Service script for starting/stopping the FlexiWAN integration daemon
# This script is called by pfSense service management
#
# @package FlexiWAN
# @author Manus AI
# @version 1.0.0

. /etc/rc.subr

name="flexiwan"
rcvar="flexiwan_enable"
pidfile="/var/run/${name}.pid"
logfile="/var/log/${name}.log"
daemon_path="/usr/local/bin/flexiwand"
config_path="/usr/local/pkg/flexiwan"

# Source pfSense functions
if [ -f /etc/rc.subr ]; then
    . /etc/rc.subr
fi

# Check if the daemon exists
if [ ! -f "${daemon_path}" ]; then
    echo "Error: Daemon not found at ${daemon_path}"
    exit 1
fi

start_cmd="${name}_start"
stop_cmd="${name}_stop"
status_cmd="${name}_status"
restart_cmd="${name}_restart"

flexiwan_start() {
    echo "Starting FlexiWAN integration daemon..."
    
    # Check if already running
    if [ -f "${pidfile}" ] && kill -0 $(cat "${pidfile}") 2>/dev/null; then
        echo "FlexiWAN daemon is already running"
        return 0
    fi
    
    # Start the daemon
    ${daemon_path} -c ${config_path} >> ${logfile} 2>&1 &
    echo $! > ${pidfile}
    
    echo "FlexiWAN daemon started (PID: $(cat ${pidfile}))"
}

flexiwan_stop() {
    echo "Stopping FlexiWAN integration daemon..."
    
    if [ -f "${pidfile}" ]; then
        pid=$(cat "${pidfile}")
        if kill -0 ${pid} 2>/dev/null; then
            kill ${pid}
            echo "FlexiWAN daemon stopped"
            rm -f "${pidfile}"
        else
            echo "FlexiWAN daemon is not running"
            rm -f "${pidfile}"
        fi
    else
        echo "FlexiWAN daemon is not running"
    fi
}

flexiwan_status() {
    if [ -f "${pidfile}" ]; then
        pid=$(cat "${pidfile}")
        if kill -0 ${pid} 2>/dev/null; then
            echo "FlexiWAN daemon is running (PID: ${pid})"
            return 0
        else
            echo "FlexiWAN daemon is not running (stale PID file)"
            return 1
        fi
    else
        echo "FlexiWAN daemon is not running"
        return 1
    fi
}

flexiwan_restart() {
    flexiwan_stop
    sleep 1
    flexiwan_start
}

# Run the command
if [ $# -eq 0 ]; then
    echo "Usage: $0 {start|stop|status|restart}"
    exit 1
fi

case "$1" in
    start)
        flexiwan_start
        ;;
    stop)
        flexiwan_stop
        ;;
    status)
        flexiwan_status
        ;;
    restart)
        flexiwan_restart
        ;;
    *)
        echo "Unknown command: $1"
        echo "Usage: $0 {start|stop|status|restart}"
        exit 1
        ;;
esac

exit $?
