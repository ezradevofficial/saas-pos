# NFR-07, ADR 025: which WAL-G base backups to keep, from
# `wal-g backup-list --json --detail`. Arguments: $now (epoch seconds),
# $pitr (days), $daily (days), $monthly (months). Prints one action per line:
#   mark <backup>     make it permanent (a kept daily or monthly older than
#                     the point-in-time window)
#   unmark <backup>   no longer kept: let the next delete remove it
#   delete-before <backup>
#                     delete every non-permanent backup and the WAL older
#                     than this one, the oldest backup the point-in-time
#                     window needs
# Permanent backups and their own WAL survive `wal-g delete`.
def ts: (.finish_time // .time) | sub("\\.[0-9]+"; "") | sub("\\+00:00$"; "Z") | fromdateiso8601;

($now | tonumber) as $now
| map(. + {t: ts}) | sort_by(.t) as $all
| ($now - ($pitr | tonumber) * 86400) as $window
| ($now - ($daily | tonumber) * 86400) as $daily_start
| ([$all[] | select(.t >= $daily_start)] | group_by(.t | strftime("%Y-%m-%d")) | map(max_by(.t).backup_name)) as $dailies
| ($all | group_by(.t | strftime("%Y-%m")) | map(min_by(.t)) | sort_by(.t) | .[-($monthly | tonumber):] | map(.backup_name)) as $monthlies
| ($dailies + $monthlies | unique) as $keep
# The newest backup finished at or before the window's start: restoring it
# and replaying WAL reaches any moment in the window.
| ([$all[] | select(.t <= $window)] | last) as $anchor
| if $anchor == null then empty
  else
    ($all[] | select(.t < $anchor.t) |
      if (.backup_name | IN($keep[])) then (if .is_permanent then empty else "mark \(.backup_name)" end)
      else (if .is_permanent then "unmark \(.backup_name)" else empty end)
      end),
    (if any($all[]; .t < $anchor.t) then "delete-before \($anchor.backup_name)" else empty end)
  end
