function initializePopup() {

    const modal = document.getElementById("enquiryModal");

    document.querySelectorAll(".enquiry-btn").forEach(btn => {
        btn.onclick = function(e){
            e.preventDefault();
            modal.classList.add("active");
        };
    });

    document.querySelector(".close-modal").onclick = function(){
        modal.classList.remove("active");
    };

    modal.onclick = function(e){
        if(e.target === modal){
            modal.classList.remove("active");
        }
    };
}