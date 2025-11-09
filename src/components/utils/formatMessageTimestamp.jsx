// In your utils/formatMessageTimestamp.js file
const formatMessageTimestamp = (timestamp) => {
    if (!timestamp) return '';

    // Create a Date object from the UTC ISO string
    const messageDate = new Date(timestamp);

    const now = new Date();
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);

    const isToday = messageDate.toDateString() === now.toDateString();
    const isYesterday = messageDate.toDateString() === yesterday.toDateString();

    const time = messageDate.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    });

    if (isToday) {
        return time;
    } else if (isYesterday) {
        return `Yesterday, ${time}`;
    } else {
        return `${messageDate.toLocaleDateString([], {
            month: 'short',
            day: 'numeric'
        })}, ${time}`;
    }
};

export default formatMessageTimestamp;