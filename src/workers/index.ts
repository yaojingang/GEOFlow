const queueNames = ["doubao-sampling", "content-generation", "report-generation"];

console.log("geo.youngtuo.win worker booted.");
console.log(`Registered queues: ${queueNames.join(", ")}`);
console.log("Connect BullMQ processors here after Doubao and database credentials are configured.");
