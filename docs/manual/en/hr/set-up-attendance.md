---
id: hr-set-up-attendance
title: Set time rules, attendance devices and manual punches
module: hr
order: 35
permissions: [hr.attendance.view, hr.attendance.manage, hr.device.manage]
roles: [hr_officer]
screens: [/hr/attendance, /hr/attendance/punches, /hr/attendance/rules, /hr/attendance/devices]
---
Punches are the clock-ins and clock-outs. Time rules decide how worked time is classified. A period cannot close without a time rule.

1. Open "Attendance" under "People" and the "Time rules" tab. Tap "Add time rule".
2. Enter "In force from" (and "In force until" if it ends). Rules are dated and may not overlap.
3. Set "Daily overtime after" and "Weekly overtime after" in minutes, for example 480 for 8 hours. Leave a threshold empty for none.
4. Set the "Night starts" and "Night ends" times, the "Late arrival grace" and "Early leaving grace", and the "Weekly rest days". Save. Payroll applies the rates.
5. Open the "Punches" tab to read every clock-in and clock-out. To add one by hand, tap "Add punch", pick the employee and "Date and time", and write the note that says why. A wrong punch is voided with "Void", never changed.
6. For a biometric terminal, open the "Devices" tab and tap "Add device". Enter the "Name" and, for your own notes, the "Vendor".
7. The device's token is shown once. Tap "Copy token", paste it into the terminal or its bridge, then tap "Done".
8. Later, use "New token" if a token is lost, "Suspend" to refuse its punches until you tap "Resume", or "Retire" to refuse the device for good. Past punches stay.

An employee may clock on the web, on a till with a PIN, or on a device, depending on how HR set up the person. See "Approve timesheets and close an attendance period" for the next step.
